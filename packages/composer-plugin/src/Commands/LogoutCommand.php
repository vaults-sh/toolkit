<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;

final class LogoutCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:logout')
            ->setDescription('Remove stored Vaults credentials for the current team, one team, or all teams')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Forget a specific team (uuid or name) instead of the current one')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Forget every team on this machine');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();

        if ($input->getOption('all')) {
            $store->clear();
            $output->writeln('Logged out of every team.');

            return self::SUCCESS;
        }

        $wanted = $input->getOption('team');
        $team = is_string($wanted) && $wanted !== '' ? TeamsCommand::find($store, $wanted) : $store->team();

        if ($team === null) {
            if ($store->token() !== null) {
                $store->clear();
                $output->writeln('Logged out.');

                return self::SUCCESS;
            }

            $output->writeln('Nothing to log out of.');

            return self::SUCCESS;
        }

        $store->forget($team->uuid);
        $output->writeln('Logged out of '.($team->name ?? $team->uuid).'.');

        return self::SUCCESS;
    }
}
