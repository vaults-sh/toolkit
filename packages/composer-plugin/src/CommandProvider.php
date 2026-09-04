<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new ProtectCommand,
        ];
    }
}
