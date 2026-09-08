<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class LoginCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:login')
            ->setDescription('Authenticate Composer with your Vaults team')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Authenticate with an existing team API token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pasted = $input->getOption('token');

        if (is_string($pasted) && $pasted !== '') {
            try {
                $team = $this->client()->withToken($pasted)->whoami();
            } catch (VaultsException $exception) {
                $output->writeln('<error>That token was rejected: '.$exception->getMessage().'</error>');

                return self::FAILURE;
            }

            $this->store()->save($pasted, $team);
            $output->writeln('Logged in to team: '.($team->name ?? 'unknown'));

            return self::SUCCESS;
        }

        if (! $input->isInteractive()) {
            $output->writeln('<error>Run "composer vaults:login" in an interactive terminal, or pass --token=<team api token>.</error>');

            return self::FAILURE;
        }

        return $this->deviceLogin($output) === null ? self::FAILURE : self::SUCCESS;
    }
}
