<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;

final class RepositoriesRemoveCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:repositories:remove')
            ->setDescription('Remove this project\'s stored credentials for a Composer repository')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (overrides .vaults.json)')
            ->addArgument('host', InputArgument::REQUIRED, 'The host shown by composer vaults:repositories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = strtolower(trim((string) $input->getArgument('host')));
        $override = $input->getOption('project');
        $projectUuid = is_string($override) && $override !== '' ? $override : (new ProjectManifest)->load($this->directory());

        if ($projectUuid === null) {
            $output->writeln('<error>This directory is not linked to a Vaults project. Pass --project=<uuid>.</error>');

            return self::FAILURE;
        }

        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $match = null;

            foreach ($client->listRepositoryCredentials($projectUuid) as $credential) {
                if ($credential->host === $host) {
                    $match = $credential;
                }
            }

            if ($match === null) {
                $output->writeln('<error>No credentials stored for '.$host.' on this project.</error>');

                return self::FAILURE;
            }

            $client->deleteRepositoryCredential($match->uuid);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        $output->writeln('<info>Removed the credentials for '.$host.'. This project is no longer authorised for its packages.</info>');

        return self::SUCCESS;
    }
}
