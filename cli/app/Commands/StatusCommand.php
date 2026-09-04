<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\VaultsClient;

class StatusCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'status {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'Show the deposit status of this project';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        try {
            $projectUuid = $this->resolveProject($client, $manifest, (string) getcwd());
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($projectUuid === null) {
            return self::FAILURE;
        }

        try {
            $projects = $client->listProjects();
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($projects as $project) {
            if ($project->uuid !== $projectUuid) {
                continue;
            }

            $this->info($project->name);
            $this->table(['Metric', 'Value'], [
                ['Deposit', $project->depositPercentage.'%'],
                ['Repository published', $project->repositoryPublished ? 'yes' : 'no'],
                ['Latest run', $project->latestRun?->status ?? 'never'],
                ['Latest run packages', $project->latestRun !== null ? $project->latestRun->packagesDeposited.'/'.$project->latestRun->packagesTotal : '-'],
            ]);

            return self::SUCCESS;
        }

        $this->error('Project '.$projectUuid.' was not found for your team.');

        return self::FAILURE;
    }
}
