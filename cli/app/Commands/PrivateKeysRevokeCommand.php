<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class PrivateKeysRevokeCommand extends Command
{
    protected $signature = 'private:keys:revoke {key : The key uuid shown by vaults private:keys}';

    protected $description = 'Revoke a private access key; the edges refuse it within a minute';

    public function handle(VaultsClient $client): int
    {
        try {
            $client->revokePrivateKey((string) $this->argument('key'));
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Key revoked. Installs using it will get 401 within a minute.');

        return self::SUCCESS;
    }
}
