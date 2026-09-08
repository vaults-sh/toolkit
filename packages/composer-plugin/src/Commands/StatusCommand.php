<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\ProjectLinker;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;

final class StatusCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:status')
            ->setDescription('Show the deposit status of this project')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (overrides .vaults.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        $override = $input->getOption('project');

        try {
            $projectUuid = (new ProjectLinker($client, new ProjectManifest, $this->resolveIO(), $output))
                ->resolve($this->directory(), is_string($override) ? $override : null, $input->isInteractive());

            if ($projectUuid === null) {
                return self::FAILURE;
            }

            $project = $client->findProject($projectUuid);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($project === null) {
            $output->writeln('<error>Project '.$projectUuid.' was not found for your team.</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>'.$project->name.'</info>');

        (new Table($output))
            ->setHeaders(['Metric', 'Value'])
            ->setRows([
                ['Deposit', $project->depositPercentage.'%'],
                ['Repository published', $project->repositoryPublished ? 'yes' : 'no'],
                ['Latest run', $project->latestRun?->status ?? 'never'],
                ['Latest run packages', $project->latestRun !== null ? $project->latestRun->packagesDeposited.'/'.$project->latestRun->packagesTotal : '-'],
            ])
            ->render();

        return self::SUCCESS;
    }
}
