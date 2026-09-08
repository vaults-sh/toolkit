<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Project\ProjectManifest;

final class OpenCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:open')->setDescription('Open the Vaults dashboard in your browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appUrl = getenv('VAULTS_APP_URL');
        $base = is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/') : 'https://vaults.sh';
        $url = $base.'/dashboard';

        $output->writeln('Opening '.$url);

        if ((new ProjectManifest)->load($this->directory()) === null) {
            $output->writeln('This directory is not linked to a project; run "composer vaults:init" to link it.');
        }

        if ($input->isInteractive()) {
            $this->openBrowser($url);
        }

        return self::SUCCESS;
    }
}
