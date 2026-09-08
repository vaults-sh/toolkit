<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Composer\PrivateLink;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class PrivateLinkCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:link')
            ->setDescription('Configure this project to install your team\'s private Vaults packages')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write the access key to your global Composer auth.json instead of this project')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Days until the key expires (1-730)', '365')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Key name shown in team settings (defaults to this machine\'s hostname)');
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

        return self::SUCCESS;
    }
}
