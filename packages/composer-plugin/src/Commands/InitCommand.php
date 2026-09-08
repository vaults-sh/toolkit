<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\ProjectLinker;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;

final class InitCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:init')
            ->setDescription('Link this directory to a Vaults project without depositing anything yet')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID (skips the interactive picker)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->directory();
        $manifest = new ProjectManifest;
        $override = $input->getOption('project');

        if ($manifest->load($directory) !== null && ! (is_string($override) && $override !== '')) {
            $output->writeln('<info>This directory is already linked (.vaults.json). Run "composer vaults:status" or "composer deposit --check".</info>');

            return self::SUCCESS;
        }

        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $projectUuid = (new ProjectLinker($client, $manifest, $this->resolveIO(), $output))
                ->resolve($directory, is_string($override) ? $override : null, $input->isInteractive());
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($projectUuid === null) {
            return self::FAILURE;
        }

        $output->writeln('Next: "composer deposit --check" to see what needs depositing, or "composer deposit" to deposit everything.');

        return self::SUCCESS;
    }
}
