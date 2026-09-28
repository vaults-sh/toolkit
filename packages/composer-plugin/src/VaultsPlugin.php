<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;
use Vaults\Auth\CredentialResolver;
use Vaults\Auth\TokenStore;
use Vaults\ComposerPlugin\Support\AutoPin;
use Vaults\Project\ProjectManifest;
use Vaults\Support\NativeSleeper;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

class VaultsPlugin implements Capable, EventSubscriberInterface, PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    public function onPostUpdate(Event $event): void
    {
        try {
            $this->autoDeposit($event);
        } catch (Throwable) {
            // Auto-deposit is best-effort; never break a composer update.
        }
    }

    private function autoDeposit(Event $event): void
    {
        $io = $event->getIO();
        $directory = $this->workingDirectory();

        if ($this->optedOut($event)) {
            return;
        }

        $credentials = (new CredentialResolver($this->tokenStore()))->resolve($directory);
        $projectUuid = (new ProjectManifest)->load($directory);

        if ($credentials === null || $projectUuid === null) {
            return;
        }

        $token = $credentials->token;

        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            return;
        }

        $pin = new AutoPin($this->client()->withToken($token), $this->sleeper());

        foreach ($pin($projectUuid, $directory, $this->waitSeconds($event)) as $line) {
            $io->write($line);
        }
    }

    protected function sleeper(): Sleeper
    {
        return new NativeSleeper;
    }

    protected function workingDirectory(): string
    {
        return (string) getcwd();
    }

    protected function tokenStore(): TokenStore
    {
        return new TokenStore;
    }

    protected function client(): VaultsClient
    {
        return new VaultsClient;
    }

    private function waitSeconds(Event $event): int
    {
        if (in_array(getenv('VAULTS_AUTO_PIN'), ['0', 'false', 'off'], true)) {
            return 0;
        }

        $extra = $event->getComposer()->getPackage()->getExtra();
        $settings = is_array($extra['vaults'] ?? null) ? $extra['vaults'] : [];

        if (($settings['auto-pin'] ?? true) === false) {
            return 0;
        }

        $wait = $settings['auto-pin-wait'] ?? AutoPin::DefaultWaitSeconds;

        return is_int($wait) ? max(0, min($wait, AutoPin::MaximumWaitSeconds)) : AutoPin::DefaultWaitSeconds;
    }

    private function optedOut(Event $event): bool
    {
        $extra = $event->getComposer()->getPackage()->getExtra();

        return isset($extra['vaults']['auto-deposit']) && $extra['vaults']['auto-deposit'] === false;
    }
}
