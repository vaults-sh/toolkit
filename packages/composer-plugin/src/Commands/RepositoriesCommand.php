<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Result\RepositoryCredential;

final class RepositoriesCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:repositories')
            ->setDescription('List the third-party private Composer repositories this project has given Vaults credentials for')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show credentials for every project in the team, not only this one')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (overrides .vaults.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        $override = $input->getOption('project');
        $projectUuid = $input->getOption('all')
            ? null
            : (is_string($override) && $override !== '' ? $override : (new ProjectManifest)->load($this->directory()));

        try {
            $credentials = $client->listRepositoryCredentials($projectUuid);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($credentials === []) {
            $output->writeln(($projectUuid === null ? 'No repository credentials.' : 'No repository credentials for this project.').' Add one with "composer vaults:repositories:add <host>".');

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Host', 'Project', 'Type', 'Last used'])
            ->setRows(array_map(fn (RepositoryCredential $credential): array => [
                $credential->host,
                $credential->projectName ?? '-',
                $credential->typeLabel(),
                $credential->lastUsedAt !== null ? substr($credential->lastUsedAt, 0, 10) : 'never',
            ], $credentials))
            ->render();

        return self::SUCCESS;
    }
}
