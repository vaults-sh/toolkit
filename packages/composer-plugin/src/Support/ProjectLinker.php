<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Support;

use Composer\IO\IOInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Exception\ApiException;
use Vaults\Project\ProjectManifest;
use Vaults\Project\ProjectName;
use Vaults\Result\Project;
use Vaults\VaultsClient;

final readonly class ProjectLinker
{
    public function __construct(
        private VaultsClient $client,
        private ProjectManifest $manifest,
        private IOInterface $io,
        private OutputInterface $output,
    ) {}

    public function resolve(string $directory, ?string $override, bool $interactive): ?string
    {
        if (is_string($override) && $override !== '') {
            if ($this->manifest->load($directory) !== $override) {
                $this->manifest->write($directory, $override);
                $this->output->writeln('Linked this directory to project '.$override.' (.vaults.json written, commit it).');
            }

            return $override;
        }

        $existing = $this->manifest->load($directory);

        if ($existing !== null) {
            return $existing;
        }

        if (! $interactive) {
            $this->output->writeln('<error>This directory is not linked to a Vaults project. Pass --project=<uuid> (or commit a .vaults.json) for non-interactive use.</error>');

            return null;
        }

        return $this->linkInteractively($directory);
    }

    private function linkInteractively(string $directory): string
    {
        $projects = $this->client->listProjects();
        $project = null;

        if ($projects !== []) {
            $options = ['+ Create a new project'];

            foreach ($projects as $candidate) {
                $options[] = $candidate->name;
            }

            $selected = (int) $this->io->select('Which Vaults project should this directory belong to?', $options, '0');

            if ($selected > 0) {
                $project = $projects[$selected - 1];
            }
        }

        if ($project === null) {
            $project = $this->createProject($directory);
        }

        $this->manifest->write($directory, $project->uuid);
        $this->output->writeln('Linked this directory to "'.$project->name.'" (.vaults.json written, commit it).');

        return $project->uuid;
    }

    private function createProject(string $directory): Project
    {
        $suggested = ProjectName::suggest($directory);

        while (true) {
            $answer = (string) $this->io->ask('What should the new project be called? ['.$suggested.'] ', $suggested);
            $name = ProjectName::normalise($answer !== '' ? $answer : $suggested);

            if (! ProjectName::isValid($name)) {
                $this->output->writeln('<error>'.ProjectName::RULE.'</error>');

                continue;
            }

            try {
                return $this->client->createProject($name);
            } catch (ApiException $exception) {
                if (! $exception->isValidationError()) {
                    throw $exception;
                }

                $this->output->writeln('<error>'.($exception->firstError('name') ?? $exception->getMessage()).'</error>');
                $suggested = $name;
            }
        }
    }
}
