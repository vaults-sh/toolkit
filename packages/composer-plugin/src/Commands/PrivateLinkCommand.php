<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Composer\PrivateLink;
use Vaults\ComposerPlugin\DepositCommand;
use Vaults\ComposerPlugin\Support\ComposerJsonRepositories;
use Vaults\ComposerPlugin\Support\ProjectLinker;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\VaultsClient;

final class PrivateLinkCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:link')
            ->setDescription('Configure this project to install your team\'s private Vaults packages')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write the access key to your global Composer auth.json instead of this project')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Days until the key expires (1-730)', '365')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Key name shown in team settings (defaults to this machine\'s hostname)')
            ->addOption('with-public', null, InputOption::VALUE_NONE, 'Also add this project\'s public Vaults repository without asking')
            ->addOption('no-public', null, InputOption::VALUE_NONE, 'Never offer a public Vaults repository')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project UUID for the public repository (overrides .vaults.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expires = (int) $input->getOption('expires');

        if ($expires < 1 || $expires > 730) {
            $output->writeln('<error>--expires must be between 1 and 730 days.</error>');

            return self::FAILURE;
        }

        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        $name = $input->getOption('name');

        try {
            $key = (new PrivateLink($client))->issueKey(is_string($name) && $name !== '' ? $name : PrivateLink::defaultKeyName(), $expires);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($key->token === null || $key->host === null || $key->repositoryUrl === null) {
            $output->writeln('<error>The API did not return a key value.</error>');

            return self::FAILURE;
        }

        $directory = $this->directory();
        $writer = $this->writer();

        if ($writer->hasRepository($directory, $key->repositoryUrl)) {
            $output->writeln('<fg=green>✓</> The private Vaults repository is already configured in composer.json.');
        } elseif ($writer->addPrivateRepository($directory, $key->repositoryUrl)) {
            $output->writeln('<info>Added the private Vaults repository to composer.json.</info>');
        } else {
            $output->writeln('<error>Could not update composer.json. Add this repository manually:</error>');
            $output->writeln('  "repositories": [{ "type": "composer", "url": "'.$key->repositoryUrl.'", "canonical": false }]');

            return self::FAILURE;
        }

        $global = (bool) $input->getOption('global');
        $authPath = $global ? $writer->globalAuthPath() : $directory.DIRECTORY_SEPARATOR.'auth.json';

        if (! $writer->writeBearerToken($authPath, $key->host, $key->token)) {
            $output->writeln('<error>Could not write the access key to '.$authPath.'.</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>Created private access key "'.$key->name.'" and wrote it to '.$authPath.'.</info>');

        if (! $global) {
            $output->writeln('<comment>Do not commit auth.json - it contains your access key. Add it to .gitignore.</comment>');
        }

        if ($key->expiresAt !== null) {
            $output->writeln('The key expires '.substr($key->expiresAt, 0, 10).'. Revoke it any time in team settings or with "composer vaults:private:keys:revoke '.$key->uuid.'"; re-running this command rotates it.');
        }

        $output->writeln('');
        $output->writeln('You can now run: composer require <vendor/package> for your private packages.');

        $this->offerPublicMirror($client, $input, $output, $directory);

        return self::SUCCESS;
    }

    private function offerPublicMirror(VaultsClient $client, InputInterface $input, OutputInterface $output, string $directory): void
    {
        $choice = $this->publicMirrorChoice($input);

        if ($choice === 'none') {
            $output->writeln('Run "composer deposit" later to route public packages through Vaults as well.');

            return;
        }

        $this->wireProjectMirror($client, $input, $output, $directory);
    }

    private function publicMirrorChoice(InputInterface $input): string
    {
        if ($input->getOption('no-public')) {
            return 'none';
        }

        if ($input->getOption('with-public')) {
            return 'project';
        }

        return $input->isInteractive() && $this->resolveIO()->askConfirmation('Also install public packages through your Vaults mirror? [Y/n] ')
            ? 'project'
            : 'none';
    }

    private function wireProjectMirror(VaultsClient $client, InputInterface $input, OutputInterface $output, string $directory): void
    {
        $override = $input->getOption('project');

        try {
            $projectUuid = (new ProjectLinker($client, new ProjectManifest, $this->resolveIO(), $output, $this->activeTeam?->uuid))
                ->resolve($directory, is_string($override) ? $override : null, $input->isInteractive());

            if ($projectUuid === null) {
                return;
            }

            $project = $client->findProject($projectUuid);
        } catch (VaultsException $exception) {
            $this->reportFailure($exception, $output);

            return;
        }

        if ($project === null) {
            $output->writeln('<error>Project '.$projectUuid.' was not found for your team.</error>');

            return;
        }

        $url = $project->repositorySnippet['url'] ?? null;

        if (! $project->repositoryPublished || ! is_string($url) || $url === '') {
            $output->writeln('Depositing this project so its repository exists...');
            $this->sibling(DepositCommand::class)->run(new ArrayInput([]), $output);

            return;
        }

        $this->wire($output, $directory, $project->repositorySnippet, $url, 'public');
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    private function wire(OutputInterface $output, string $directory, array $repository, string $url, string $label): void
    {
        if (ComposerJsonRepositories::has($directory, $repository)) {
            $output->writeln('<fg=green>✓</> The '.$label.' Vaults repository is already configured in composer.json.');

            return;
        }

        if (ComposerJsonRepositories::add($directory, 'vaults', $repository)) {
            $output->writeln('<info>Added the '.$label.' Vaults repository to composer.json. Commit it along with .vaults.json.</info>');

            return;
        }

        $output->writeln('<error>Could not update composer.json. Add this repository manually:</error>');
        $output->writeln('  { "type": "composer", "url": "'.$url.'", "canonical": false }');
    }
}
