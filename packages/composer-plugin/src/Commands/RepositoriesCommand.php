<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Result\RepositoryCredential;

final class RepositoriesCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:repositories')->setDescription('List the third-party private Composer repositories your team has given Vaults credentials for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $credentials = $client->listRepositoryCredentials();
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($credentials === []) {
            $output->writeln('No repository credentials. Add one with "composer vaults:repositories:add <host>".');

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Host', 'Type', 'Last used'])
            ->setRows(array_map(fn (RepositoryCredential $credential): array => [
                $credential->host,
                $credential->typeLabel(),
                $credential->lastUsedAt !== null ? substr($credential->lastUsedAt, 0, 10) : 'never',
            ], $credentials))
            ->render();

        return self::SUCCESS;
    }
}
