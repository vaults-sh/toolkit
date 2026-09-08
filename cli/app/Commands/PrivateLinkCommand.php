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

use function Laravel\Prompts\select;

class PrivateLinkCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'private:link
        {--global : Write the access key to your global Composer auth.json instead of this project}
        {--expires=365 : Days until the key expires (1-730)}
        {--name= : Key name shown in team settings (defaults to this machine\'s hostname)}
        {--with-public : Also add this project\'s public Vaults repository without asking}
        {--global-mirror : Add the global Vaults mirror instead of this project\'s repository}
        {--no-public : Never offer a public Vaults repository}
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
        $choice = $this->publicMirrorChoice();

        if ($choice === 'none') {
            $this->line('Run vaults deposit later to route public packages through Vaults as well.');

            return;
        }

        if ($choice === 'global') {
            $this->wireGlobalMirror($client, $writer, $directory);

            return;
        }

        $this->wireProjectMirror($client, $writer, $manifest, $directory);
    }

    private function publicMirrorChoice(): string
    {
        if ($this->option('no-public')) {
            return 'none';
        }

        if ($this->option('global-mirror')) {
            return 'global';
        }

        if ($this->option('with-public') || ! $this->input->isInteractive()) {
            return $this->option('with-public') ? 'project' : 'none';
        }

        return select('Also install public packages through your Vaults mirror?', [
            'project' => 'Yes, this project\'s mirror - only versions Vaults verified for you',
            'global' => 'Yes, the global mirror - every package Vaults has ever mirrored, yours not guaranteed',
            'none' => 'No, keep installing public packages from Packagist',
        ], 'project');
    }

    private function wireGlobalMirror(VaultsClient $client, ComposerConfigWriter $writer, string $directory): void
    {
        try {
            $url = $client->repositories()->globalUrl();
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        if ($url === null) {
            $this->error('The API did not return a global repository url.');

            return;
        }

        $this->wire($writer, $directory, $url, 'global');
        $this->line('The global mirror serves whatever Vaults has mirrored. Run vaults deposit to guarantee this project\'s own dependencies.');
    }

    private function wireProjectMirror(VaultsClient $client, ComposerConfigWriter $writer, ProjectManifest $manifest, string $directory): void
    {
        try {
            $projectUuid = $this->resolveProject($client, $manifest, $directory);

            if ($projectUuid === null) {
                return;
            }

            $project = $client->findProject($projectUuid);
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        if ($project === null) {
            $this->error('Project '.$projectUuid.' was not found for your team.');

            return;
        }

        $url = $project->repositorySnippet['url'] ?? null;

        if (! $project->repositoryPublished || ! is_string($url) || $url === '') {
            $this->line('Depositing this project so its repository exists...');
            $this->call('deposit');

            return;
        }

        $this->wire($writer, $directory, $url, 'public');
    }

    private function wire(ComposerConfigWriter $writer, string $directory, string $url, string $label): void
    {
        if ($writer->hasRepository($directory, $url)) {
            $this->line('<fg=green>✓</> The '.$label.' Vaults repository is already configured in composer.json.');

            return;
        }

        if ($writer->addRepository($directory, $url)) {
            $this->info('Added the '.$label.' Vaults repository to composer.json. Commit it along with .vaults.json.');

            return;
        }

        $this->error('Could not update composer.json. Add this repository manually:');
        $this->line('  { "type": "composer", "url": "'.$url.'", "canonical": false }');
    }
}
