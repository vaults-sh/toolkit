<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Package\Locker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Composer\AuthJson;
use Vaults\Composer\PrivateRepositoryDetector;
use Vaults\ComposerPlugin\Support\ComposerJsonRepositories;
use Vaults\ComposerPlugin\Support\ProjectLinker;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Report\DepositReport;
use Vaults\Result\DepositRun;
use Vaults\VaultsClient;

final class DepositCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:deposit')
            ->setAliases(['deposit'])
            ->setDescription('Deposit the dependencies in composer.lock with Vaults')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report deposit status without starting a run')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Overwrite composer.lock with the rewritten Vaults version')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (overrides .vaults.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->directory();
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            $output->writeln('<error>No composer.lock found in '.$directory.'.</error>');

            return self::FAILURE;
        }

        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        $override = $input->getOption('project');

        try {
            $projectUuid = (new ProjectLinker($client, new ProjectManifest, $this->resolveIO(), $output, $this->activeTeam?->uuid))
                ->resolve($directory, is_string($override) ? $override : null, $input->isInteractive());

            if ($projectUuid === null) {
                return self::FAILURE;
            }

            $lock = (string) file_get_contents($lockPath);

            if ($input->getOption('check')) {
                return $this->check($client, $projectUuid, $lock, $output);
            }

            return $this->deposit($client, $projectUuid, $lock, $lockPath, (bool) $input->getOption('write'), $output, $directory, $input->isInteractive());
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }
    }

    private function check(VaultsClient $client, string $projectUuid, string $lock, OutputInterface $output): int
    {
        $result = $client->depositCheck($projectUuid, $lock);

        foreach ($result->packages as $package) {
            $output->writeln(sprintf(
                '  %s %s %s%s',
                $package->deposited ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $package->name,
                $package->version,
                $package->securityStatus !== null && $package->securityStatus !== 'clear' ? ' <comment>['.$package->securityStatus.']</comment>' : '',
            ));
        }

        $output->writeln($result->deposited.'/'.$result->total.' deposited, '.$result->undeposited.' undeposited.');

        if (! $result->isFullyDeposited()) {
            $output->writeln('<comment>Run "composer vaults:deposit" to deposit the remaining packages.</comment>');

            return self::FAILURE;
        }

        $output->writeln('<info>All packages are deposited.</info>');

        return self::SUCCESS;
    }

    /** @var list<string> */
    private array $declinedHosts = [];

    private function deposit(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, bool $write, OutputInterface $output, string $directory, bool $interactive, bool $retried = false): int
    {
        if (! $retried) {
            $this->offerPrivateRepositories($client, $lock, $directory, $output, $interactive);
        }

        $run = $client->deposit($projectUuid, $lock);

        $output->writeln('Deposit run started.');

        while (! $run->isFinished()) {
            $this->sleeper()->sleep(2);
            $run = $client->getRun($run->uuid);
            $output->write("\r".'Deposited '.$run->packagesDeposited.'/'.$run->packagesTotal.'...');
        }

        $report = new DepositReport('composer vaults:');

        $output->writeln('');
        $output->writeln($report->summary($run));
        $output->writeln($report->coverage($run));

        foreach ($report->problems($run) as $line) {
            $output->writeln($line);
        }

        if ($run->status !== 'completed') {
            $output->writeln('<error>The deposit run failed. Run "composer vaults:open" to inspect it.</error>');

            return self::FAILURE;
        }

        if (! $retried && $this->offerCredentials($client, $run, $directory, $output, $interactive)) {
            return $this->deposit($client, $projectUuid, $lock, $lockPath, $write, $output, $directory, $interactive, retried: true);
        }

        $rewritten = $client->getRewrittenLock($run->uuid);

        $this->finishWiring($rewritten->projectRepository, $directory, $output, $interactive);

        foreach ($report->privateHint($run, ComposerJsonRepositories::has($directory, $rewritten->privateRepository)) as $line) {
            $output->writeln($line);
        }

        if ($write) {
            file_put_contents($lockPath, $this->withRefreshedContentHash($rewritten->composerLock, $directory));
            $output->writeln('<info>composer.lock rewritten to install from Vaults. Run composer install.</info>');
        } else {
            $output->writeln('Run "composer vaults:deposit --write" to rewrite composer.lock, then "composer install".');
        }

        if ($run->packagesFailed > 0) {
            $output->writeln('<comment>'.$run->packagesFailed.' package'.($run->packagesFailed === 1 ? '' : 's').' did not deposit; installs still depend on their original hosts.</comment>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function offerPrivateRepositories(VaultsClient $client, string $lock, string $directory, OutputInterface $output, bool $interactive): void
    {
        if (! $interactive) {
            return;
        }

        $detector = new PrivateRepositoryDetector;

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
            $output->writeln('composer.lock has '.$count.' package'.($count === 1 ? '' : 's').' from '.$repository['host'].' ('.implode(', ', array_slice($repository['packages'], 0, 3)).($count > 3 ? ', …' : '').'), and '.$repository['credentials']['source'].' has credentials for it.');

            if (! $this->resolveIO()->askConfirmation('Let Vaults use those credentials to deposit them privately for your team? [Y/n] ')) {
                $this->declinedHosts[] = $repository['host'];
                $output->writeln('Skipping '.$repository['host'].'. Run "composer vaults:repositories:add '.$repository['host'].'" later to deposit them.');

                continue;
            }

            try {
                $client->storeRepositoryCredential($repository['host'], $repository['credentials']['type'], $repository['credentials']['secret'], $repository['credentials']['username']);
                $output->writeln('<info>Saved credentials for '.$repository['host'].'. Its packages will be deposited privately for your team.</info>');
            } catch (VaultsException $exception) {
                $output->writeln('<error>'.$exception->getMessage().'</error>');
            }
        }
    }

    private function offerCredentials(VaultsClient $client, DepositRun $run, string $directory, OutputInterface $output, bool $interactive): bool
    {
        if (! $interactive) {
            return false;
        }

        $uploaded = false;

        foreach ($run->hostsNeedingCredentials() as $host) {
            if (in_array($host, $this->declinedHosts, true)) {
                continue;
            }

            $found = (new AuthJson)->credentialsFor($host, $directory);

            if ($found === null) {
                continue;
            }

            if (! $this->resolveIO()->askConfirmation('Give Vaults the credentials for '.$host.' from '.$found['source'].' and deposit again? [Y/n] ')) {
                continue;
            }

            try {
                $client->storeRepositoryCredential($host, $found['type'], $found['secret'], $found['username']);
            } catch (VaultsException $exception) {
                $output->writeln('<error>'.$exception->getMessage().'</error>');

                continue;
            }

            $output->writeln('<info>Saved credentials for '.$host.'. Its packages will be deposited privately for your team.</info>');
            $uploaded = true;
        }

        return $uploaded;
    }

    private function withRefreshedContentHash(string $lockJson, string $directory): string
    {
        $composerJsonPath = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($composerJsonPath)) {
            return $lockJson;
        }

        $hash = Locker::getContentHash((string) file_get_contents($composerJsonPath));

        return (string) preg_replace('/"content-hash":\s*"[a-f0-9]+"/', '"content-hash": "'.$hash.'"', $lockJson, 1);
    }

    /**
     * @param  array<string, mixed>  $projectRepository
     */
    private function finishWiring(array $projectRepository, string $directory, OutputInterface $output, bool $interactive): void
    {
        if ($projectRepository === []) {
            return;
        }

        if (ComposerJsonRepositories::has($directory, $projectRepository)) {
            $output->writeln('<fg=green>✓</> The public Vaults repository is already configured in composer.json.');

            return;
        }

        $snippet = (string) json_encode($projectRepository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($interactive) {
            $output->writeln('This will be added to the "repositories" section of composer.json:');
            $output->writeln('<fg=gray>'.$snippet.'</>');

            if ($this->resolveIO()->askConfirmation('Add it now? [Y/n] ')
                && ComposerJsonRepositories::add($directory, 'vaults', $projectRepository)
            ) {
                $output->writeln('<info>composer.json updated, commit it along with .vaults.json.</info>');

                return;
            }
        }

        $output->writeln('Add this to the "repositories" section of composer.json:');
        $output->writeln($snippet);
    }
}
