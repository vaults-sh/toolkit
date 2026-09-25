<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class RepositoriesRemoveCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:repositories:remove')
            ->setDescription('Remove the stored credentials for a Composer repository')
            ->addArgument('host', InputArgument::REQUIRED, 'The host shown by composer vaults:repositories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = strtolower(trim((string) $input->getArgument('host')));
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $match = null;

            foreach ($client->listRepositoryCredentials() as $credential) {
                if ($credential->host === $host) {
                    $match = $credential;
                }
            }

            if ($match === null) {
                $output->writeln('<error>No credentials stored for '.$host.'.</error>');

                return self::FAILURE;
            }

            $client->deleteRepositoryCredential($match->uuid);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        $output->writeln('<info>Removed the credentials for '.$host.'. Packages already deposited stay in your private repository.</info>');

        return self::SUCCESS;
    }
}
