<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\VaultsClient;

class RepositoriesRemoveCommand extends Command
{
    protected $signature = 'repositories:remove
        {host : The host shown by vaults repositories}
        {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'Remove this project\'s stored credentials for a Composer repository';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        $host = strtolower(trim((string) $this->argument('host')));
        $override = $this->option('project');
        $projectUuid = is_string($override) && $override !== '' ? $override : $manifest->load((string) getcwd());

        if ($projectUuid === null) {
            $this->error('This directory is not linked to a Vaults project. Pass --project=<uuid>.');

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
                $this->error('No credentials stored for '.$host.' on this project.');

                return self::FAILURE;
            }

            $client->deleteRepositoryCredential($match->uuid);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Removed the credentials for '.$host.'. This project is no longer authorised for its packages.');

        return self::SUCCESS;
    }
}
