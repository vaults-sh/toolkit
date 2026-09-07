<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ComposerConfigWriter;
use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class PrivateKeysCreateCommand extends Command
{
    protected $signature = 'private:keys:create
        {name : A label such as "GitHub Actions" or "Client X deploy"}
        {--project= : Project uuid to group the key under}
        {--package=* : Restrict the key to these private packages (vendor/name)}
        {--expires=365 : Days until the key expires (1-730)}
        {--write : Write the key into this project\'s auth.json instead of printing it}';

    protected $description = 'Create a long-lived, revocable private access key for CI or a client project';

    public function handle(VaultsClient $client, ComposerConfigWriter $writer): int
    {
        $expires = (int) $this->option('expires');

        if ($expires < 1 || $expires > 730) {
            $this->error('--expires must be between 1 and 730 days.');

            return self::FAILURE;
        }

        /** @var list<string> $packages */
        $packages = array_values(array_filter((array) $this->option('package'), 'is_string'));
        $project = $this->option('project');

        try {
            $key = $client->createPrivateKey(
                (string) $this->argument('name'),
                is_string($project) && $project !== '' ? $project : null,
                $packages,
                $expires,
            );
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($key->token === null || $key->host === null) {
            $this->error('The API did not return a key value.');

            return self::FAILURE;
        }

        $this->info('Created private access key "'.$key->name.'" ('.$key->uuid.') for '.$key->scopeLabel().'.');

        if ($this->option('write')) {
            $authPath = (string) getcwd().DIRECTORY_SEPARATOR.'auth.json';

            if (! $writer->writeBearerToken($authPath, $key->host, $key->token)) {
                $this->error('Could not write the key to '.$authPath.'.');

                return self::FAILURE;
            }

            $this->info('Wrote the key to '.$authPath.'. Do not commit auth.json.');
        } else {
            $this->newLine();
            $this->line('This is the only time the key is shown:');
            $this->line('  '.$key->token);
            $this->newLine();
            $this->line('Add it to auth.json in the consuming project, or set COMPOSER_AUTH in CI:');
            $this->line('  '.json_encode(['bearer' => [$key->host => $key->token]], JSON_UNESCAPED_SLASHES));
        }

        if ($key->expiresAt !== null) {
            $this->line('Expires '.substr($key->expiresAt, 0, 10).'. Revoke early with vaults private:keys:revoke '.$key->uuid.'.');
        }

        return self::SUCCESS;
    }
}
