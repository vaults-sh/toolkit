<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Json\JsonManipulator;
use Composer\Package\Locker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\ProjectLinker;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
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
            $projectUuid = (new ProjectLinker($client, new ProjectManifest, $this->resolveIO(), $output))
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
            $output->writeln('<comment>Run "composer deposit" to deposit the remaining packages.</comment>');

            return self::FAILURE;
        }

        $output->writeln('<info>All packages are deposited.</info>');

        return self::SUCCESS;
    }

    private function deposit(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, bool $write, OutputInterface $output, string $directory, bool $interactive): int
    {
        $run = $client->deposit($projectUuid, $lock);

        $output->writeln('Deposit run started.');

        while (! $run->isFinished()) {
            $this->sleeper()->sleep(2);

            $run = $client->getRun($run->uuid);

            $output->write("\r".'Deposited '.$run->packagesDeposited.'/'.$run->packagesTotal.'...');
        }

        $output->writeln('');
        $output->writeln('Deposited: '.$run->packagesDeposited.' · Skipped: '.$run->packagesSkipped.' · Failed: '.$run->packagesFailed);

        if ($run->status !== 'completed') {
            $output->writeln('<error>The deposit run failed. See the Vaults dashboard for details.</error>');

            return self::FAILURE;
        }

        $rewritten = $client->getRewrittenLock($run->uuid);

        $this->finishWiring($rewritten->projectRepository, $directory, $output, $interactive);

        if ($write) {
            file_put_contents($lockPath, $this->withRefreshedContentHash($rewritten->composerLock, $directory));
            $output->writeln('<info>composer.lock rewritten to install from Vaults. Run composer install.</info>');
        } else {
            $output->writeln('Run "composer deposit --write" to rewrite composer.lock, then "composer install".');
        }

        return self::SUCCESS;
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

        if ($this->repositoryAlreadyConfigured($directory, $projectRepository)) {
            $output->writeln('<fg=green>✓</> The Vaults repository is already configured in composer.json.');

            return;
        }

        $snippet = (string) json_encode($projectRepository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($interactive) {
            $output->writeln('This will be added to the "repositories" section of composer.json:');
            $output->writeln('<fg=gray>'.$snippet.'</>');

            if ($this->resolveIO()->askConfirmation('Add it now? [Y/n] ')
                && $this->wireRepository($directory, $projectRepository)
            ) {
                $output->writeln('<info>composer.json updated, commit it along with .vaults.json.</info>');

                return;
            }
        }

        $output->writeln('Add this to the "repositories" section of composer.json:');
        $output->writeln($snippet);
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    private function repositoryAlreadyConfigured(string $directory, array $repository): bool
    {
        $wantedUrl = rtrim((string) ($repository['url'] ?? ''), '/');

        if ($wantedUrl === '') {
            return false;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $repositories = is_array($decoded) && is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];

        foreach ($repositories as $entry) {
            if (is_array($entry) && rtrim((string) ($entry['url'] ?? ''), '/') === $wantedUrl) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    private function wireRepository(string $directory, array $repository): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return false;
        }

        $manipulator = new JsonManipulator((string) file_get_contents($path));

        if (! $manipulator->addRepository('vaults', $repository, true)) {
            return false;
        }

        file_put_contents($path, $manipulator->getContents());

        return true;
    }
}
