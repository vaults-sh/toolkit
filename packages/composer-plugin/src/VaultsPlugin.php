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
use Vaults\Auth\TokenStore;
use Vaults\Project\ProjectManifest;
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

        $token = $this->tokenStore()->token();
        $projectUuid = (new ProjectManifest)->load($directory);

        if ($token === null || $projectUuid === null) {
            return;
        }

        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            return;
        }

        $run = $this->client()->withToken($token)->deposit($projectUuid, (string) file_get_contents($lockPath));

        $io->write('<info>Vaults:</info> depositing '.$run->packagesTotal.' packages in the background. Run "composer deposit --write" to pin composer.lock to Vaults.');
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

    private function optedOut(Event $event): bool
    {
        $extra = $event->getComposer()->getPackage()->getExtra();

        return isset($extra['vaults']['auto-deposit']) && $extra['vaults']['auto-deposit'] === false;
    }
}
