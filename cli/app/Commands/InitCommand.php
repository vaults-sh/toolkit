<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\VaultsClient;

class InitCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'init {--project= : Project UUID (skips the interactive picker)}';

    protected $description = 'Link this directory to a Vaults project without depositing anything yet';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        $directory = (string) getcwd();

        if ($manifest->load($directory) !== null && ! $this->option('project')) {
            $this->info('This directory is already linked (.vaults.json). Run vaults status or vaults deposit --check.');

            return self::SUCCESS;
        }

        try {
            $projectUuid = $this->resolveProject($client, $manifest, $directory);
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

        $this->line('Next: vaults deposit --check to see what needs depositing, or vaults deposit to deposit everything.');

        return self::SUCCESS;
    }
}
