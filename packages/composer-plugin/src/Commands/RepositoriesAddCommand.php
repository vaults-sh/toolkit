<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Composer\AuthJson;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class RepositoriesAddCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:repositories:add')
            ->setDescription('Give Vaults the credentials for a paid or private Composer repository so its packages can be deposited')
            ->addArgument('host', InputArgument::REQUIRED, 'The repository host, e.g. satis.example.com')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'http-basic or bearer')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username for http-basic')
            ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Password or token (prefer auth.json or the prompt over passing this on the command line)')
            ->addOption('from-auth', null, InputOption::VALUE_NONE, 'Take the credentials from auth.json without asking');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = strtolower(trim((string) $input->getArgument('host')));
        $type = $input->getOption('type');
        $username = $input->getOption('username');
        $secret = $input->getOption('secret');
        $interactive = $input->isInteractive();

        $found = (new AuthJson)->credentialsFor($host, $this->directory());

        if (! is_string($secret) || $secret === '') {
            if ($found !== null && ($input->getOption('from-auth') || ! $interactive || $this->resolveIO()->askConfirmation('Use the credentials for '.$host.' from '.$found['source'].'? [Y/n] '))) {
                $type = $found['type'];
                $username = $found['username'];
                $secret = $found['secret'];
            } elseif (! $interactive) {
                $output->writeln('<error>No credentials for '.$host.' in auth.json. Pass --type, --username and --secret, or run interactively.</error>');

                return self::FAILURE;
            } else {
                $io = $this->resolveIO();
                $type = is_string($type) && $type !== '' ? $type : ($io->askConfirmation('Is this an HTTP basic repository (username and password)? [Y/n] ') ? 'http-basic' : 'bearer');
                $username = $type === 'http-basic' ? (is_string($username) && $username !== '' ? $username : (string) $io->ask('Username: ')) : null;
                $secret = (string) $io->askAndHideAnswer($type === 'bearer' ? 'Token: ' : 'Password: ');
            }
        }

        $type = is_string($type) && $type !== '' ? $type : ($username !== null ? 'http-basic' : 'bearer');

        if (! in_array($type, ['http-basic', 'bearer'], true)) {
            $output->writeln('<error>--type must be http-basic or bearer.</error>');

            return self::FAILURE;
        }

        if ($type === 'http-basic' && (! is_string($username) || $username === '')) {
            $output->writeln('<error>HTTP basic credentials need --username.</error>');

            return self::FAILURE;
        }

        if (! is_string($secret) || $secret === '') {
            $output->writeln('<error>A password or token is required.</error>');

            return self::FAILURE;
        }

        $client = $this->authenticatedClient($output, $interactive);

        if ($client === null) {
            return self::FAILURE;
        }

        try {
            $credential = $client->storeRepositoryCredential($host, $type, $secret, $type === 'http-basic' ? $username : null);
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        $output->writeln('<info>Saved '.$credential->typeLabel().' credentials for '.$credential->host.'. Vaults will use them to deposit that host\'s packages privately for your team.</info>');
        $output->writeln('By saving them you confirm your team is licensed for the packages on this host. Run "composer vaults:deposit" to pick them up.');

        return self::SUCCESS;
    }
}
