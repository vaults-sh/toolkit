<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Result\RepositoryCredential;
use Vaults\VaultsClient;

class RepositoriesCommand extends Command
{
    protected $signature = 'repositories';

    protected $description = 'List the third-party private Composer repositories your team has given Vaults credentials for';

    public function handle(VaultsClient $client): int
    {
        try {
            $credentials = $client->listRepositoryCredentials();
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($credentials === []) {
            $this->line('No repository credentials. Add one with vaults repositories:add <host>.');

            return self::SUCCESS;
        }

        $this->table(
            ['Host', 'Type', 'Last used'],
            array_map(fn (RepositoryCredential $credential): array => [
                $credential->host,
                $credential->typeLabel(),
                $credential->lastUsedAt !== null ? substr($credential->lastUsedAt, 0, 10) : 'never',
            ], $credentials),
        );

        return self::SUCCESS;
    }
}
