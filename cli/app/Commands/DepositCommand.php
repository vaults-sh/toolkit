<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Vaults\Composer\AuthJson;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\Composer\LockContentHash;
use Vaults\Composer\PrivateLink;
use Vaults\Composer\PrivateRepositoryDetector;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Report\DepositReport;
use Vaults\Result\CheckPackage;
use Vaults\Result\DepositRun;
use Vaults\Result\RewrittenLock;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

class DepositCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'deposit
        {--check : Report deposit status without starting a run}
        {--write : Overwrite composer.lock with the rewritten Vaults version}
        {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'Deposit the dependencies in composer.lock with Vaults';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        $directory = (string) getcwd();
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            $this->error('No composer.lock found in '.$directory.'.');

            return self::FAILURE;
        }

        $lock = (string) file_get_contents($lockPath);

        try {
            $projectUuid = $this->resolveProject($client, $manifest, $directory);

            if ($projectUuid === null) {
                return self::FAILURE;
            }

            if ($this->option('check')) {
                return $this->check($client, $projectUuid, $lock);
            }

            return $this->deposit($client, $projectUuid, $lock, $lockPath, $directory);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function check(VaultsClient $client, string $projectUuid, string $lock): int
    {
        $result = spin(fn () => $client->depositCheck($projectUuid, $lock), 'Checking deposit status...');

        $this->table(
            ['Package', 'Version', 'Private', 'Deposited', 'Security'],
            array_map(fn (CheckPackage $package): array => [
                $package->name,
                $package->version,
                $package->privateLabel() === null ? '' : '<fg=cyan>'.$package->privateLabel().'</>',
                $package->deposited ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $this->securityLabel($package->securityStatus),
            ], $result->packages),
        );

        $this->line($result->deposited.'/'.$result->total.' deposited, '.$result->undeposited.' undeposited.');

        if (! $result->isFullyDeposited()) {
            $this->warn('Run vaults deposit to deposit the remaining packages.');

            return self::FAILURE;
        }

        $this->info('All packages are deposited.');

        return self::SUCCESS;
    }

    /** @var list<string> */
    private array $declinedHosts = [];

    private function deposit(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, string $directory, bool $retried = false): int
    {
        if (! $retried) {
            $this->offerPrivateRepositories($client, $projectUuid, $lock, $directory);
        }

        $report = new DepositReport('vaults ');

        foreach ($report->heading($retried ? 'Deposit again with the new credentials' : 'Deposit') as $line) {
            $this->line($line);
        }

        $run = spin(fn () => $client->deposit($projectUuid, $lock), 'Starting the deposit run...');
        $run = $this->awaitRun($client, $this->laravel->make(Sleeper::class), $run);

        $this->line($report->summary($run));
        $this->line($report->coverage($run));

        foreach ($report->problems($run) as $line) {
            $this->line($line);
        }

        if ($run->status !== 'completed') {
            $this->error('The deposit run failed. Run vaults open to inspect it.');

            return self::FAILURE;
        }

        if (! $retried && $this->offerCredentials($client, $projectUuid, $run, $directory)) {
            return $this->deposit($client, $projectUuid, $lock, $lockPath, $directory, retried: true);
        }

        $rewritten = spin(fn () => $client->getRewrittenLock($run->uuid), 'Fetching the rewritten lock...');

        foreach ($report->heading('Install from Vaults') as $line) {
            $this->line($line);
        }

        $this->offerRepositoryWiring($rewritten, $directory);
        $this->offerPrivateWiring($client, $projectUuid, $run, $rewritten, $directory);

        foreach ($report->heading($this->option('write') ? 'Done' : 'Next step') as $line) {
            $this->line($line);
        }

        if ($this->option('write')) {
            file_put_contents($lockPath, resolve(LockContentHash::class)->refresh($rewritten->composerLock, $directory.DIRECTORY_SEPARATOR.'composer.json'));
            $this->line('<fg=green>✓</> composer.lock now installs from Vaults. Nothing to reinstall here.');
            $this->line('<fg=gray>Commit composer.json, composer.lock and .vaults.json.</>');

            if ($run->depositedPrivateItems() !== []) {
                $this->line('<fg=gray>CI and servers need a private access key for '.($rewritten->privateRepository['url'] ?? 'your private repository').' in auth.json or COMPOSER_AUTH: vaults private:keys:create "CI" --project='.$projectUuid.'</>');
            } else {
                $this->line('<fg=gray>Installing needs no Vaults token, in CI or anywhere else.</>');
            }
        } else {
            $this->line('Run <options=bold>vaults deposit --write</> to pin composer.lock to Vaults.');
        }

        if ($run->packagesFailed > 0) {
            $this->newLine();
            $this->line('<fg=red>'.$run->packagesFailed.' package'.($run->packagesFailed === 1 ? '' : 's').' did not deposit</>; installs still depend on their original hosts.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function offerPrivateWiring(VaultsClient $client, string $projectUuid, DepositRun $run, RewrittenLock $rewritten, string $directory): void
    {
        $privateUrl = $rewritten->privateRepository['url'] ?? null;
        $writer = resolve(ComposerConfigWriter::class);
        $configured = is_string($privateUrl) && $writer->hasRepository($directory, $privateUrl);
        $private = $run->depositedPrivateItems();

        if ($private === [] || $configured) {
            return;
        }

        foreach ((new DepositReport('vaults '))->privateWiring($private) as $line) {
            $this->line($line);
        }

        if (! $this->input->isInteractive() || ! confirm('Set this project up to install private packages from Vaults?')) {
            $this->line('<fg=gray>→</> Run <options=bold>vaults private:link</> when you are ready to install them from Vaults.');

            return;
        }

        try {
            $wired = (new PrivateLink($client))->wire($writer, $directory, $directory.DIRECTORY_SEPARATOR.'auth.json', PrivateLink::defaultKeyName(), 365, $projectUuid);
        } catch (VaultsException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->line('<fg=green>✓</> Added the private Vaults repository to composer.json and wrote key "'.$wired['key']->name.'" to ./auth.json.');
        $this->line('<comment>Do not commit auth.json. Revoke the key any time in team settings or with vaults private:keys:revoke '.$wired['key']->uuid.'.</comment>');
    }

    private function offerPrivateRepositories(VaultsClient $client, string $projectUuid, string $lock, string $directory): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        $detector = resolve(PrivateRepositoryDetector::class);

        if ($detector->detect($lock, $directory, []) === []) {
            return;
        }

        try {
            $projectCredentials = $client->listRepositoryCredentials($projectUuid);
        } catch (VaultsException) {
            $projectCredentials = [];
        }

        $repositories = $detector->detect($lock, $directory, $projectCredentials);

        foreach ((new DepositReport('vaults '))->privateRepositories($repositories) as $line) {
            $this->line($line);
        }

        foreach ($repositories as $repository) {
            if (! confirm('Use the '.$repository['host'].' credentials?')) {
                $this->declinedHosts[] = $repository['host'];
                $this->line('<fg=gray>Skipping '.$repository['host'].'. Run vaults repositories:add '.$repository['host'].' later to deposit those packages.</>');

                continue;
            }

            try {
                $client->storeRepositoryCredential($projectUuid, $repository['host'], $repository['credentials']['type'], $repository['credentials']['secret'], $repository['credentials']['username']);
                $this->line('<fg=green>✓</> Saved credentials for <fg=cyan>'.$repository['host'].'</> against this project.');
            } catch (VaultsException $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    private function offerCredentials(VaultsClient $client, string $projectUuid, DepositRun $run, string $directory): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        $uploaded = false;

        foreach ($run->hostsNeedingCredentials() as $host) {
            if (in_array($host, $this->declinedHosts, true)) {
                continue;
            }

            $found = resolve(AuthJson::class)->credentialsFor($host, $directory);

            if ($found === null) {
                continue;
            }

            if (! confirm('Authorise this project with the '.$host.' credentials from '.$found['source'].' and deposit again?')) {
                continue;
            }

            try {
                $client->storeRepositoryCredential($projectUuid, $host, $found['type'], $found['secret'], $found['username']);
            } catch (VaultsException $exception) {
                $this->error($exception->getMessage());

                continue;
            }

            $this->line('<fg=green>✓</> Saved credentials for <fg=cyan>'.$host.'</> against this project.');
            $uploaded = true;
        }

        return $uploaded;
    }

    private function awaitRun(VaultsClient $client, Sleeper $sleeper, DepositRun $run): DepositRun
    {
        $run = spin(function () use ($client, $sleeper, $run): DepositRun {
            while (! $run->isFinished() && $run->packagesTotal === 0) {
                $sleeper->sleep(1);

                $run = $client->getRun($run->uuid);
            }

            return $run;
        }, 'Analysing composer.lock...');

        if (! $run->isFinished() && ! $run->analysed) {
            $checking = progress(label: 'Checking '.$run->packagesTotal.' '.($run->packagesTotal === 1 ? 'package' : 'packages').' against Vaults...', steps: $run->packagesTotal);
            $checking->start();
            $checked = 0;

            while (! $run->isFinished() && ! $run->analysed) {
                if ($run->packagesChecked() > $checked) {
                    $checking->advance($run->packagesChecked() - $checked);
                    $checked = $run->packagesChecked();
                }

                $sleeper->sleep(1);

                $run = $client->getRun($run->uuid);
            }

            if ($run->packagesTotal > $checked) {
                $checking->advance($run->packagesTotal - $checked);
            }

            $checking->finish();
        }

        $scope = (new DepositReport('vaults '))->scope($run);

        if ($scope !== null) {
            $this->line($scope);
        }

        if ($run->isFinished()) {
            return $run;
        }

        $total = $run->packagesToDeposit();

        if ($total === 0) {
            return spin(function () use ($client, $sleeper, $run): DepositRun {
                while (! $run->isFinished()) {
                    $sleeper->sleep(1);

                    $run = $client->getRun($run->uuid);
                }

                return $run;
            }, 'Checking the repository is up to date...');
        }

        $progress = progress(label: 'Depositing '.$total.' '.($run->packagesAlreadyDeposited === null ? '' : 'new ').($total === 1 ? 'package' : 'packages').'...', steps: $total);
        $progress->start();
        $reported = 0;

        while (! $run->isFinished()) {
            $sleeper->sleep(2);

            $run = $client->getRun($run->uuid);

            $done = $run->packagesProcessed();

            if ($done > $reported) {
                $progress->hint($run->packagesNewlyDeposited().' deposited · '.$run->packagesSkipped.' skipped'.($run->packagesPrivate > 0 ? ' ('.$run->packagesPrivate.' private)' : '').' · '.$run->packagesFailed.' failed');
                $progress->advance($done - $reported);
                $reported = $done;
            }
        }

        $progress->finish();

        return $run;
    }

    private function offerRepositoryWiring(RewrittenLock $rewritten, string $directory): void
    {
        $url = $rewritten->projectRepository['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        if (resolve(ComposerConfigWriter::class)->hasRepository($directory, $url)) {
            $this->line('<fg=green>✓</> The public Vaults repository is already configured in composer.json.');

            return;
        }

        $snippet = (string) json_encode($rewritten->projectRepository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->input->isInteractive()) {
            $this->line('This will be added to the "repositories" section of composer.json:');
            $this->line('<fg=gray>'.$snippet.'</>');

            if (confirm('Add it now?')) {
                if (resolve(ComposerConfigWriter::class)->addRepository($directory, $url)) {
                    $this->info('composer.json updated, commit it along with .vaults.json.');

                    return;
                }

                $this->warn('Could not update composer.json automatically.');
            }
        }

        $this->line('Add this to the "repositories" section of composer.json:');
        $this->line($snippet);
    }

    private function securityLabel(?string $status): string
    {
        return match ($status) {
            'clear' => '<info>clear</info>',
            'flagged' => '<comment>flagged</comment>',
            'blocked' => '<error>blocked</error>',
            null => '-',
            default => $status,
        };
    }
}
