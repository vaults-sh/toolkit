<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;

final class ConnectCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:connect')->setDescription('Open the Vaults dashboard to connect a git provider and choose repositories to host');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appUrl = getenv('VAULTS_APP_URL');
        $base = is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/') : 'https://vaults.sh';

        $output->writeln('Connecting a provider happens in your browser.');
        $output->writeln('Open '.$base.', add the provider under Team settings -> Connections, then pick the');
        $output->writeln('repositories to host on the Private Packages page.');

        if ($input->isInteractive()) {
            $this->openBrowser($base);
        }

        $output->writeln('');
        $output->writeln('Once a repository is hosted, run "composer vaults:private:link" here to install its packages.');

        return self::SUCCESS;
    }
}
