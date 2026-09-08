<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class PrivateLinkCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:link')
            ->setDescription('Configure this project to install your team\'s private Vaults packages')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write the access token to your global Composer auth.json instead of this project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $token = $client->createPrivateToken();
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        $directory = $this->directory();
        $writer = $this->writer();

        if ($writer->hasRepository($directory, $token->repositoryUrl)) {
            $output->writeln('<fg=green>✓</> The private Vaults repository is already configured in composer.json.');
        } elseif ($writer->addPrivateRepository($directory, $token->repositoryUrl)) {
            $output->writeln('<info>Added the private Vaults repository to composer.json.</info>');
        } else {
            $output->writeln('<error>Could not update composer.json. Add this repository manually:</error>');
            $output->writeln('  "repositories": [{ "type": "composer", "url": "'.$token->repositoryUrl.'", "canonical": false }]');

            return self::FAILURE;
        }

        $global = (bool) $input->getOption('global');
        $authPath = $global ? $writer->globalAuthPath() : $directory.DIRECTORY_SEPARATOR.'auth.json';

        if (! $writer->writeBearerToken($authPath, $token->host, $token->token)) {
            $output->writeln('<error>Could not write the access token to '.$authPath.'.</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>Wrote the access token to '.$authPath.'.</info>');

        if (! $global) {
            $output->writeln('<comment>Do not commit auth.json - it contains your access token. Add it to .gitignore.</comment>');
        }

        if ($token->expiresAt !== null) {
            $output->writeln('This token expires '.date('Y-m-d H:i', $token->expiresAt).'. Run "composer vaults:private:link" again to refresh it.');
        }

        $output->writeln('');
        $output->writeln('You can now run: composer require <vendor/package> for your private packages.');

        return self::SUCCESS;
    }
}
