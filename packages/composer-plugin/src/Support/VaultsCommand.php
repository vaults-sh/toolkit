<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Support;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Auth\CredentialResolver;
use Vaults\Auth\DeviceFlow;
use Vaults\Auth\TokenStore;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\Diagnostics\EdgeProbe;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Result\TeamIdentity;
use Vaults\Support\NativeSleeper;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

abstract class VaultsCommand extends BaseCommand
{
    public function __construct(
        private ?VaultsClient $client = null,
        private ?TokenStore $store = null,
        private ?string $workingDirectory = null,
        private ?Sleeper $sleeper = null,
        private ?IOInterface $io = null,
        private ?EdgeProbe $probe = null,
        private ?ComposerConfigWriter $writer = null,
    ) {
        parent::__construct();
    }

    protected function sibling(string $commandClass): VaultsCommand
    {
        $command = new $commandClass($this->client, $this->store, $this->workingDirectory, $this->sleeper, $this->io, $this->probe, $this->writer);
        $command->setApplication($this->getApplication());

        return $command;
    }

    protected function directory(): string
    {
        return $this->workingDirectory ?? (string) getcwd();
    }

    protected function store(): TokenStore
    {
        return $this->store ??= new TokenStore;
    }

    protected function client(): VaultsClient
    {
        return $this->client ??= new VaultsClient;
    }

    protected function sleeper(): Sleeper
    {
        return $this->sleeper ??= new NativeSleeper;
    }

    protected function probe(): EdgeProbe
    {
        return $this->probe ??= new EdgeProbe;
    }

    protected function writer(): ComposerConfigWriter
    {
        return $this->writer ??= new ComposerConfigWriter;
    }

    protected function resolveIO(): IOInterface
    {
        return $this->io ?? $this->getIO();
    }

    protected ?TeamIdentity $activeTeam = null;

    protected function resolver(): CredentialResolver
    {
        return new CredentialResolver($this->store());
    }

    protected function authenticatedClient(OutputInterface $output, bool $interactive): ?VaultsClient
    {
        $credentials = $this->resolver()->resolve($this->directory());

        if ($credentials === null && $interactive) {
            $missing = $this->resolver()->missingTeam($this->directory());

            if ($missing !== null) {
                $output->writeln('This project belongs to team '.$missing.', which is not stored on this machine yet.');
            }

            $token = $this->deviceLogin($output);
            $credentials = $token === null ? null : $this->resolver()->resolve($this->directory());

            if ($token !== null && $credentials === null) {
                $output->writeln('<error>The team you logged in to is not the team recorded in .vaults.json ('.$missing.'). Log in to that team, or remove the team line from .vaults.json.</error>');
            }
        }

        if ($credentials === null) {
            $missing = $this->resolver()->missingTeam($this->directory());
            $output->writeln($missing !== null
                ? '<error>Not authenticated for team '.$missing.'. Run "composer vaults:login" in an interactive terminal and approve it for that team, or set the VAULTS_TOKEN environment variable.</error>'
                : '<error>Not authenticated. Run "composer vaults:login" in an interactive terminal, or set the VAULTS_TOKEN environment variable.</error>');

            return null;
        }

        $this->activeTeam = $credentials->team;

        return $this->client()->withToken($credentials->token);
    }

    protected function deviceLogin(OutputInterface $output): ?string
    {
        $flow = new DeviceFlow($this->client(), $this->sleeper());

        try {
            $pair = $flow->start((string) (gethostname() ?: 'composer-plugin'));
        } catch (VaultsException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return null;
        }

        $output->writeln('First, copy your device code: <info>'.$pair->userCode.'</info>');
        $output->writeln('Then approve it at: <info>'.$pair->verificationUriComplete.'</info>');
        $output->writeln('Waiting for approval...');

        $result = $flow->await($pair);

        if ($result->isDenied()) {
            $output->writeln('<error>This sign-in was denied in the browser. Nothing was saved.</error>');

            return null;
        }

        if (! $result->isApproved() || $result->token === null) {
            $output->writeln('<error>The device code expired before it was approved.</error>');

            return null;
        }

        $this->store()->save($result->token, $result->team);
        $output->writeln('Logged in to team: '.($result->team?->name ?? 'unknown'));

        return $result->token;
    }

    protected function reportFailure(VaultsException $exception, OutputInterface $output): int
    {
        $output->writeln($exception instanceof AuthenticationException
            ? '<error>Your Vaults token was rejected. Run "composer vaults:login" again.</error>'
            : '<error>'.$exception->getMessage().'</error>');

        return self::FAILURE;
    }

    protected function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        @exec($command.' '.escapeshellarg($url).' > /dev/null 2>&1 &');
    }
}
