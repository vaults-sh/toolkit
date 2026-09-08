<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Vaults\ComposerPlugin\Commands\ConnectCommand;
use Vaults\ComposerPlugin\Commands\DoctorCommand;
use Vaults\ComposerPlugin\Commands\InitCommand;
use Vaults\ComposerPlugin\Commands\LoginCommand;
use Vaults\ComposerPlugin\Commands\LogoutCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysCreateCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysRevokeCommand;
use Vaults\ComposerPlugin\Commands\PrivateLinkCommand;
use Vaults\ComposerPlugin\Commands\StatusCommand;
use Vaults\ComposerPlugin\Commands\TeamsCommand;

final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new DepositCommand,
            new LoginCommand,
            new LogoutCommand,
            new TeamsCommand,
            new InitCommand,
            new StatusCommand,
            new DoctorCommand,
            new ConnectCommand,
            new PrivateLinkCommand,
            new PrivateKeysCommand,
            new PrivateKeysCreateCommand,
            new PrivateKeysRevokeCommand,
        ];
    }
}
