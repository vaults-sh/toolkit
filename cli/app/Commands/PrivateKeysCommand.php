<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Result\PrivateKey;
use Vaults\VaultsClient;

class PrivateKeysCommand extends Command
{
    protected $signature = 'private:keys';

    protected $description = 'List the active private access keys for your team';

    public function handle(VaultsClient $client): int
    {
        try {
            $keys = $client->listPrivateKeys();
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($keys === []) {
            $this->line('No private access keys. Create one with vaults private:keys:create <name>.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Name', 'Scope', 'Expires'],
            array_map(fn (PrivateKey $key): array => [
                $key->uuid,
                $key->name,
                $key->scopeLabel(),
                $key->expiresAt !== null ? substr($key->expiresAt, 0, 10) : '-',
            ], $keys),
        );

        return self::SUCCESS;
    }
}
