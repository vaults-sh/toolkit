<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\Composer\PrivateLink;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class PrivateLinkCommand extends Command
{
    protected $signature = 'private:link
        {--global : Write the access key to your global Composer auth.json instead of this project}
        {--expires=365 : Days until the key expires (1-730)}
        {--name= : Key name shown in team settings (defaults to this machine\'s hostname)}';

    protected $description = 'Configure this project to install your team\'s private Vaults packages';

    public function handle(VaultsClient $client, ComposerConfigWriter $writer): int
    {
        $expires = (int) $this->option('expires');

        if ($expires < 1 || $expires > 730) {
            $this->error('--expires must be between 1 and 730 days.');

            return self::FAILURE;
        }

        $directory = (string) getcwd();
        $name = $this->option('name');

        try {
            $key = (new PrivateLink($client))->issueKey(is_string($name) && $name !== '' ? $name : PrivateLink::defaultKeyName(), $expires);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($key->token === null || $key->host === null || $key->repositoryUrl === null) {
            $this->error('The API did not return a key value.');

            return self::FAILURE;
        }

        if ($writer->hasRepository($directory, $key->repositoryUrl)) {
            $this->line('<fg=green>✓</> The private Vaults repository is already configured in composer.json.');
        } elseif ($writer->addPrivateRepository($directory, $key->repositoryUrl)) {
            $this->info('Added the private Vaults repository to composer.json.');
        } else {
            $this->error('Could not update composer.json. Add this repository manually:');
            $this->line('  "repositories": [{ "type": "composer", "url": "'.$key->repositoryUrl.'", "canonical": false }]');

            return self::FAILURE;
        }

        $authPath = $this->option('global')
            ? $writer->globalAuthPath()
            : $directory.DIRECTORY_SEPARATOR.'auth.json';

        if (! $writer->writeBearerToken($authPath, $key->host, $key->token)) {
            $this->error('Could not write the access key to '.$authPath.'.');

            return self::FAILURE;
        }

        $this->info('Created private access key "'.$key->name.'" and wrote it to '.$authPath.'.');

        if (! $this->option('global')) {
            $this->warn('Do not commit auth.json - it contains your access key. Add it to .gitignore.');
        }

        if ($key->expiresAt !== null) {
            $this->line('The key expires '.substr($key->expiresAt, 0, 10).'. Revoke it any time in team settings or with vaults private:keys:revoke '.$key->uuid.'; re-running vaults private:link rotates it.');
        }

        $this->newLine();
        $this->line('You can now run: composer require <vendor/package> for your private packages.');

        return self::SUCCESS;
    }
}
