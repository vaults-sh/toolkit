<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Composer\Package\Locker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Auth\DeviceFlow;
use Vaults\Auth\TokenStore;
use Vaults\Exception\ApiException;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Project\ProjectName;
use Vaults\Result\Project;
use Vaults\VaultsClient;

final class ProtectCommand extends BaseCommand
{
    /**
     * @param  (callable(int): void)|null  $sleep
     */
    public function __construct(
        private ?VaultsClient $client = null,
        private ?TokenStore $store = null,
        private ?string $workingDirectory = null,
        private $sleep = null,
        private ?IOInterface $io = null,
    ) {
        parent::__construct();
    }

    private function resolveIO(): IOInterface
    {
        return $this->io ?? $this->getIO();
    }

    protected function configure(): void
    {
        $this->setName('protect')
            ->setDescription('Protect the dependencies in composer.lock with Vaults')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report protection status without starting a run')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Overwrite composer.lock with the rewritten Vaults version')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (overrides .vaults.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->workingDirectory ?? (string) getcwd();
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            $output->writeln('<error>No composer.lock found in '.$directory.'.</error>');

            return self::FAILURE;
        }

        $store = $this->store ?? new TokenStore;
        $token = $store->token();

        if ($token === null && $input->isInteractive()) {
            $token = $this->deviceLogin($store, $output);
        }

        if ($token === null) {
            $output->writeln('<error>Not authenticated. Run "composer protect" in an interactive terminal to log in, or set the VAULTS_TOKEN environment variable.</error>');

            return self::FAILURE;
        }

        $client = ($this->client ?? new VaultsClient)->withToken($token);
        $manifest = new ProjectManifest;
        $projectUuid = $input->getOption('project');

        if (is_string($projectUuid) && $projectUuid !== '') {
            if ($manifest->load($directory) !== $projectUuid) {
                $manifest->write($directory, $projectUuid);
                $output->writeln('Linked this directory to project '.$projectUuid.' (.vaults.json written, commit it).');
            }
        } else {
            $projectUuid = $manifest->load($directory);
        }

        try {
            if ($projectUuid === null && $input->isInteractive()) {
                $projectUuid = $this->linkInteractively($client, $manifest, $directory, $output);
            }

            if ($projectUuid === null) {
                $output->writeln('<error>This directory is not linked to a Vaults project. Pass --project=<uuid> (or commit a .vaults.json) for non-interactive use.</error>');

                return self::FAILURE;
            }

            $lock = (string) file_get_contents($lockPath);

            if ($input->getOption('check')) {
                return $this->check($client, $projectUuid, $lock, $output);
            }

            return $this->protect($client, $projectUuid, $lock, $lockPath, (bool) $input->getOption('write'), $output, $directory, $input->isInteractive());
        } catch (AuthenticationException) {
            $output->writeln('<error>Your Vaults token was rejected. Run "vaults login" again.</error>');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }
    }

    private function linkInteractively(VaultsClient $client, ProjectManifest $manifest, string $directory, OutputInterface $output): string
    {
        $io = $this->resolveIO();
        $projects = $client->listProjects();

        $project = null;

        if ($projects !== []) {
            $options = ['+ Create a new project'];

            foreach ($projects as $candidate) {
                $options[] = $candidate->name;
            }

            $selected = (int) $io->select('Which Vaults project should this directory belong to?', $options, '0');

            if ($selected > 0) {
                $project = $projects[$selected - 1];
            }
        }

        if ($project === null) {
            $project = $this->createProject($client, $io, $output, $directory);
        }

        $manifest->write($directory, $project->uuid);
        $output->writeln('Linked this directory to "'.$project->name.'" (.vaults.json written, commit it).');

        return $project->uuid;
    }

    private function createProject(VaultsClient $client, IOInterface $io, OutputInterface $output, string $directory): Project
    {
        $suggested = ProjectName::suggest($directory);

        while (true) {
            $answer = (string) $io->ask('What should the new project be called? ['.$suggested.'] ', $suggested);
            $name = ProjectName::normalise($answer !== '' ? $answer : $suggested);

            if (! ProjectName::isValid($name)) {
                $output->writeln('<error>'.ProjectName::RULE.'</error>');

                continue;
            }

            try {
                return $client->createProject($name);
            } catch (ApiException $exception) {
                if (! $exception->isValidationError()) {
                    throw $exception;
                }

                $output->writeln('<error>'.($exception->firstError('name') ?? $exception->getMessage()).'</error>');
                $suggested = $name;
            }
        }
    }

    private function deviceLogin(TokenStore $store, OutputInterface $output): ?string
    {
        $client = $this->client ?? new VaultsClient;
        $flow = new DeviceFlow($client);

        try {
            $pair = $flow->start((string) (gethostname() ?: 'composer-plugin'));
        } catch (VaultsException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return null;
        }

        $output->writeln('First, copy your device code: <info>'.$pair->userCode.'</info>');
        $output->writeln('Then approve it at: <info>'.$pair->verificationUriComplete.'</info>');
        $output->writeln('Waiting for approval...');

        $result = $flow->await($pair, sleep: $this->sleep);

        if ($result->isDenied()) {
            $output->writeln('<error>This sign-in was denied in the browser. Nothing was saved.</error>');

            return null;
        }

        if (! $result->isApproved() || $result->token === null) {
            $output->writeln('<error>The device code expired before it was approved.</error>');

            return null;
        }

        $store->save($result->token, $result->team);
        $output->writeln('Logged in to team: '.($result->team?->name ?? 'unknown'));

        return $result->token;
    }

    private function check(VaultsClient $client, string $projectUuid, string $lock, OutputInterface $output): int
    {
        $result = $client->protectCheck($projectUuid, $lock);

        foreach ($result->packages as $package) {
            $output->writeln(sprintf(
                '  %s %s %s%s',
                $package->protected ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $package->name,
                $package->version,
                $package->securityStatus !== null && $package->securityStatus !== 'clear' ? ' <comment>['.$package->securityStatus.']</comment>' : '',
            ));
        }

        $output->writeln($result->protected.'/'.$result->total.' protected, '.$result->unprotected.' unprotected.');

        if (! $result->isFullyProtected()) {
            $output->writeln('<comment>Run "composer protect" to protect the remaining packages.</comment>');

            return self::FAILURE;
        }

        $output->writeln('<info>All packages are protected.</info>');

        return self::SUCCESS;
    }

    private function protect(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, bool $write, OutputInterface $output, string $directory, bool $interactive): int
    {
        $run = $client->protect($projectUuid, $lock);

        $output->writeln('Protection run started.');

        while (! $run->isFinished()) {
            ($this->sleep ?? static fn (int $seconds) => sleep($seconds))(2);

            $run = $client->getRun($run->uuid);

            $output->write("\r".'Protected '.$run->packagesProtected.'/'.$run->packagesTotal.'...');
        }

        $output->writeln('');
        $output->writeln('Protected: '.$run->packagesProtected.' · Skipped: '.$run->packagesSkipped.' · Failed: '.$run->packagesFailed);

        if ($run->status !== 'completed') {
            $output->writeln('<error>The protection run failed. See the Vaults dashboard for details.</error>');

            return self::FAILURE;
        }

        $rewritten = $client->getRewrittenLock($run->uuid);

        $this->finishWiring($rewritten->projectRepository, $directory, $output, $interactive);

        if ($write) {
            file_put_contents($lockPath, $this->withRefreshedContentHash($rewritten->composerLock, $directory));
            $output->writeln('<info>composer.lock rewritten to install from Vaults. Run composer install.</info>');
        } else {
            $output->writeln('Run "composer protect --write" to rewrite composer.lock, then "composer install".');
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
