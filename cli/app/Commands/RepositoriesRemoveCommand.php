<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class RepositoriesRemoveCommand extends Command
{
    protected $signature = 'repositories:remove {host : The host shown by vaults repositories}';

    protected $description = 'Remove the stored credentials for a Composer repository';

    public function handle(VaultsClient $client): int
    {
        $host = strtolower(trim((string) $this->argument('host')));

        try {
            $match = null;

            foreach ($client->listRepositoryCredentials() as $credential) {
                if ($credential->host === $host) {
                    $match = $credential;
                }
            }

            if ($match === null) {
                $this->error('No credentials stored for '.$host.'.');

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

        $this->info('Removed the credentials for '.$host.'. Packages already deposited stay in your private repository.');

        return self::SUCCESS;
    }
}
