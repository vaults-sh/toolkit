<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use LaravelZero\Framework\Commands\Command;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\Composer\PrivateLink;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\VaultsClient;

use function Laravel\Prompts\confirm;

class PrivateLinkCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'private:link
        {--global : Write the access key to your global Composer auth.json instead of this project}
        {--expires=365 : Days until the key expires (1-730)}
        {--name= : Key name shown in team settings (defaults to this machine\'s hostname)}
        {--with-public : Also add this project\'s public Vaults repository without asking}
        {--no-public : Never offer the public Vaults repository}
        {--project= : Project UUID for the public repository (overrides .vaults.json)}';

    protected $description = 'Configure this project to install your team\'s private Vaults packages';

    public function handle(VaultsClient $client, ComposerConfigWriter $writer, ProjectManifest $manifest): int
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

        $this->offerPublicMirror($client, $writer, $manifest, $directory);

        return self::SUCCESS;
    }

    private function offerPublicMirror(VaultsClient $client, ComposerConfigWriter $writer, ProjectManifest $manifest, string $directory): void
    {
        if ($this->option('no-public')) {
            return;
        }

        $wanted = (bool) $this->option('with-public')
            || ($this->input->isInteractive() && confirm('Also install public packages through your Vaults mirror?'));

        if (! $wanted) {
            $this->line('Run vaults deposit later to route public packages through Vaults as well.');

            return;
        }

        try {
            $projectUuid = $this->resolveProject($client, $manifest, $directory);
            $project = $projectUuid === null ? null : $client->findProject($projectUuid);
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        if ($projectUuid === null) {
            return;
        }

        if ($project === null) {
            $this->error('Project '.$projectUuid.' was not found for your team.');

            return;
        }

        if (! $project->repositoryPublished) {
            if ($this->input->isInteractive() && confirm('This project has not been deposited yet. Deposit it now?')) {
                $this->call('deposit');

                return;
            }

            $this->line('Run vaults deposit to publish the project repository; it wires composer.json for you.');

            return;
        }

        $url = $project->repositorySnippet['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $this->line('Run vaults deposit to publish the project repository; it wires composer.json for you.');

            return;
        }

        if ($writer->hasRepository($directory, $url)) {
            $this->line('<fg=green>✓</> The public Vaults repository is already configured in composer.json.');

            return;
        }

        if ($writer->addRepository($directory, $url)) {
            $this->info('Added the public Vaults repository to composer.json. Commit it along with .vaults.json.');

            return;
        }

        $this->error('Could not update composer.json. Add this repository manually:');
        $this->line('  '.json_encode($project->repositorySnippet, JSON_UNESCAPED_SLASHES));
    }
}
