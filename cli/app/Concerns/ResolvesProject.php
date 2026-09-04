<?php

declare(strict_types=1);

namespace App\Concerns;

use Vaults\Project\ProjectManifest;
use Vaults\Result\Project;
use Vaults\VaultsClient;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

trait ResolvesProject
{
    private function resolveProject(VaultsClient $client, ProjectManifest $manifest, string $directory): ?string
    {
        $override = $this->option('project');

        if (is_string($override) && $override !== '') {
            if ($manifest->load($directory) !== $override) {
                $manifest->write($directory, $override);
                $this->info('Linked this directory to project '.$override.' (.vaults.json written, commit it).');
            }

            return $override;
        }

        $existing = $manifest->load($directory);

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This directory is not linked to a Vaults project. Pass --project=<uuid> (or commit a .vaults.json) for non-interactive use.');

            return null;
        }

        $projects = spin(fn (): array => $client->listProjects(), 'Loading your projects...');

        $project = $projects === []
            ? $this->createProject($client, $directory)
            : $this->chooseProject($client, $projects, $directory);

        $manifest->write($directory, $project->uuid);
        $this->info('Linked this directory to "'.$project->name.'" (.vaults.json written, commit it).');

        return $project->uuid;
    }

    /**
     * @param  list<Project>  $projects
     */
    private function chooseProject(VaultsClient $client, array $projects, string $directory): Project
    {
        $createLabel = '+ Create a new project';
        $options = [$createLabel => $createLabel];

        foreach ($projects as $project) {
            $options[$project->uuid] = $project->name;
        }

        $choice = count($projects) > 5
            ? search(
                label: 'Which Vaults project should this directory belong to?',
                options: fn (string $value): array => array_filter(
                    $options,
                    fn (string $label): bool => $value === '' || stripos($label, $value) !== false,
                ),
            )
            : select('Which Vaults project should this directory belong to?', $options);

        if ($choice === $createLabel) {
            return $this->createProject($client, $directory);
        }

        foreach ($projects as $project) {
            if ($project->uuid === $choice) {
                return $project;
            }
        }

        return $this->createProject($client, $directory);
    }

    private function createProject(VaultsClient $client, string $directory): Project
    {
        $name = text(
            label: 'What should the new project be called?',
            default: $this->defaultProjectName($directory),
            required: true,
        );

        return spin(fn (): Project => $client->createProject($name), 'Creating project...');
    }

    private function defaultProjectName(string $directory): string
    {
        $composerJson = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (is_file($composerJson)) {
            $decoded = json_decode((string) file_get_contents($composerJson), true);
            $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : '';

            if (str_contains($name, '/')) {
                return explode('/', $name, 2)[1];
            }
        }

        return basename($directory);
    }
}
