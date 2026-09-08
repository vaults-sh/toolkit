<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;

final class LogoutCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:logout')->setDescription('Remove the stored Vaults credentials');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store()->clear();
        $output->writeln('Logged out.');

        return self::SUCCESS;
    }
}
