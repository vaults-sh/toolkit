<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ComposerConfigWriter;
use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class PrivateLinkCommand extends Command
{
    protected $signature = 'private:link {--global : Write the access token to your global Composer auth.json instead of this project}';

    protected $description = 'Configure this project to install your team\'s private Vaults packages';

    public function handle(VaultsClient $client, ComposerConfigWriter $writer): int
    {
        $directory = (string) getcwd();

        try {
            $token = $client->createPrivateToken();
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($writer->hasRepository($directory, $token->repositoryUrl)) {
            $this->line('<fg=green>✓</> The private Vaults repository is already configured in composer.json.');
        } elseif ($writer->addPrivateRepository($directory, $token->repositoryUrl)) {
            $this->info('Added the private Vaults repository to composer.json.');
        } else {
            $this->error('Could not update composer.json. Add this repository manually:');
            $this->line('  "repositories": [{ "type": "composer", "url": "'.$token->repositoryUrl.'", "canonical": false }]');

            return self::FAILURE;
        }

        $authPath = $this->option('global')
            ? $writer->globalAuthPath()
            : $directory.DIRECTORY_SEPARATOR.'auth.json';

        if (! $writer->writeBearerToken($authPath, $token->host, $token->token)) {
            $this->error('Could not write the access token to '.$authPath.'.');

            return self::FAILURE;
        }

        $this->info('Wrote the access token to '.$authPath.'.');

        if (! $this->option('global')) {
            $this->warn('Do not commit auth.json - it contains your access token. Add it to .gitignore.');
        }

        if ($token->expiresAt !== null) {
            $this->line('This token expires '.date('Y-m-d H:i', $token->expiresAt).'. Run vaults private:link again to refresh it.');
        }

        $this->newLine();
        $this->line('You can now run: composer require <vendor/package> for your private packages.');

        return self::SUCCESS;
    }
}
