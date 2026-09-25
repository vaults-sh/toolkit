<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use App\Services\LockContentHash;
use LaravelZero\Framework\Commands\Command;
use Vaults\Composer\AuthJson;
use Vaults\Composer\ComposerConfigWriter;
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
            ['Package', 'Version', 'Deposited', 'Security'],
            array_map(fn (CheckPackage $package): array => [
                $package->name,
                $package->version,
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
            $this->offerPrivateRepositories($client, $lock, $directory);
        }

        $run = spin(fn () => $client->deposit($projectUuid, $lock), 'Starting the deposit run...');
        $run = $this->awaitRun($client, $this->laravel->make(Sleeper::class), $run);
        $report = new DepositReport('vaults ');

        $this->line($report->summary($run));
        $this->line($report->coverage($run));

        foreach ($report->problems($run) as $line) {
            $this->line($line);
        }

        if ($run->status !== 'completed') {
            $this->error('The deposit run failed. Run vaults open to inspect it.');

            return self::FAILURE;
        }

        if (! $retried && $this->offerCredentials($client, $run, $directory)) {
            return $this->deposit($client, $projectUuid, $lock, $lockPath, $directory, retried: true);
        }

        $rewritten = spin(fn () => $client->getRewrittenLock($run->uuid), 'Fetching the rewritten lock...');

        $this->offerRepositoryWiring($rewritten, $directory);

        $privateUrl = $rewritten->privateRepository['url'] ?? null;

        foreach ($report->privateHint($run, is_string($privateUrl) && resolve(ComposerConfigWriter::class)->hasRepository($directory, $privateUrl)) as $line) {
            $this->line($line);
        }

        if ($this->option('write')) {
            file_put_contents($lockPath, resolve(LockContentHash::class)->refresh($rewritten->composerLock, $directory.DIRECTORY_SEPARATOR.'composer.json'));
            $this->info('composer.lock rewritten to install from Vaults. Run composer install.');
        } else {
            $this->line('Run vaults deposit --write to rewrite composer.lock, then composer install.');
        }

        if ($run->packagesFailed > 0) {
            $this->warn($run->packagesFailed.' package'.($run->packagesFailed === 1 ? '' : 's').' did not deposit; installs still depend on their original hosts.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function offerPrivateRepositories(VaultsClient $client, string $lock, string $directory): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        $detector = resolve(PrivateRepositoryDetector::class);

        if ($detector->detect($lock, $directory, []) === []) {
            return;
        }

        try {
            $teamCredentials = $client->listRepositoryCredentials();
        } catch (VaultsException) {
            $teamCredentials = [];
        }

        foreach ($detector->detect($lock, $directory, $teamCredentials) as $repository) {
            $count = count($repository['packages']);
            $this->line('composer.lock has '.$count.' package'.($count === 1 ? '' : 's').' from '.$repository['host'].' ('.implode(', ', array_slice($repository['packages'], 0, 3)).($count > 3 ? ', …' : '').'), and '.$repository['credentials']['source'].' has credentials for it.');

            if (! confirm('Let Vaults use those credentials to deposit them privately for your team?')) {
                $this->declinedHosts[] = $repository['host'];
                $this->line('Skipping '.$repository['host'].'. Run vaults repositories:add '.$repository['host'].' later to deposit them.');

                continue;
            }

            try {
                $client->storeRepositoryCredential($repository['host'], $repository['credentials']['type'], $repository['credentials']['secret'], $repository['credentials']['username']);
                $this->info('Saved credentials for '.$repository['host'].'. Its packages will be deposited privately for your team.');
            } catch (VaultsException $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    private function offerCredentials(VaultsClient $client, DepositRun $run, string $directory): bool
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

            if (! confirm('Give Vaults the credentials for '.$host.' from '.$found['source'].' and deposit again?')) {
                continue;
            }

            try {
                $client->storeRepositoryCredential($host, $found['type'], $found['secret'], $found['username']);
            } catch (VaultsException $exception) {
                $this->error($exception->getMessage());

                continue;
            }

            $this->info('Saved credentials for '.$host.'. Its packages will be deposited privately for your team.');
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

        if ($run->isFinished()) {
            return $run;
        }

        $total = $run->packagesTotal;
        $progress = progress(label: 'Depositing '.$total.' packages...', steps: $total);
        $progress->start();
        $reported = 0;

        while (! $run->isFinished()) {
            $sleeper->sleep(2);

            $run = $client->getRun($run->uuid);

            $done = min($total, $run->packagesDeposited + $run->packagesSkipped + $run->packagesFailed);

            if ($done > $reported) {
                $progress->hint($run->packagesDeposited.' deposited · '.$run->packagesSkipped.' skipped'.($run->packagesPrivate > 0 ? ' ('.$run->packagesPrivate.' private)' : '').' · '.$run->packagesFailed.' failed');
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
