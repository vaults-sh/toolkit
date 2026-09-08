<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class PrivateKeysRevokeCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:keys:revoke')
            ->setDescription('Revoke a private access key; the edges refuse it within a minute')
            ->addArgument('key', InputArgument::REQUIRED, 'The key uuid shown by composer vaults:private:keys');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $client->revokePrivateKey((string) $input->getArgument('key'));
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        $output->writeln('<info>Key revoked. Installs using it will get 401 within a minute.</info>');

        return self::SUCCESS;
    }
}
