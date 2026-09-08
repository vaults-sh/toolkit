<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;
use Vaults\Result\PrivateKey;

final class PrivateKeysCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:keys')->setDescription('List the active private access keys for your team');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->authenticatedClient($output, $input->isInteractive());

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $keys = $client->listPrivateKeys();
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($keys === []) {
            $output->writeln('No private access keys. Create one with "composer vaults:private:keys:create <name>".');

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Key', 'Name', 'Scope', 'Expires'])
            ->setRows(array_map(fn (PrivateKey $key): array => [
                $key->uuid,
                $key->name,
                $key->scopeLabel(),
                $key->expiresAt !== null ? substr($key->expiresAt, 0, 10) : '-',
            ], $keys))
            ->render();

        return self::SUCCESS;
    }
}
