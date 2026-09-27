<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Result\RepositoryCredential;
use Vaults\VaultsClient;

class RepositoriesCommand extends Command
{
    protected $signature = 'repositories
        {--all : Show credentials for every project in the team, not only this one}
        {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'List the third-party private Composer repositories this project has given Vaults credentials for';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        $override = $this->option('project');
        $projectUuid = $this->option('all') ? null : (is_string($override) && $override !== '' ? $override : $manifest->load((string) getcwd()));

        try {
            $credentials = $client->listRepositoryCredentials($projectUuid);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($credentials === []) {
            $this->line($projectUuid === null
                ? 'No repository credentials. Add one with vaults repositories:add <host>.'
                : 'No repository credentials for this project. Add one with vaults repositories:add <host>.');

            return self::SUCCESS;
        }

        $this->table(
            ['Host', 'Project', 'Type', 'Last used'],
            array_map(fn (RepositoryCredential $credential): array => [
                $credential->host,
                $credential->projectName ?? '-',
                $credential->typeLabel(),
                $credential->lastUsedAt !== null ? substr($credential->lastUsedAt, 0, 10) : 'never',
            ], $credentials),
        );

        return self::SUCCESS;
    }
}
