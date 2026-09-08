<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Exception\VaultsException;

final class PrivateKeysCreateCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:private:keys:create')
            ->setDescription('Create a long-lived, revocable private access key for CI or a client project')
            ->addArgument('name', InputArgument::REQUIRED, 'A label such as "GitHub Actions" or "Client X deploy"')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project uuid to group the key under')
            ->addOption('package', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the key to these private packages (vendor/name)', [])
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Days until the key expires (1-730)', '365')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write the key into this project\'s auth.json instead of printing it');
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

        /** @var list<string> $packages */
        $packages = array_values(array_filter((array) $input->getOption('package'), 'is_string'));
        $project = $input->getOption('project');

        try {
            $key = $client->createPrivateKey(
                (string) $input->getArgument('name'),
                is_string($project) && $project !== '' ? $project : null,
                $packages,
                $expires,
            );
        } catch (VaultsException $exception) {
            return $this->reportFailure($exception, $output);
        }

        if ($key->token === null || $key->host === null) {
            $output->writeln('<error>The API did not return a key value.</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>Created private access key "'.$key->name.'" ('.$key->uuid.') for '.$key->scopeLabel().'.</info>');

        if ($input->getOption('write')) {
            $authPath = $this->directory().DIRECTORY_SEPARATOR.'auth.json';

            if (! $this->writer()->writeBearerToken($authPath, $key->host, $key->token)) {
                $output->writeln('<error>Could not write the key to '.$authPath.'.</error>');

                return self::FAILURE;
            }

            $output->writeln('<info>Wrote the key to '.$authPath.'. Do not commit auth.json.</info>');
        } else {
            $output->writeln('');
            $output->writeln('This is the only time the key is shown:');
            $output->writeln('  '.$key->token);
            $output->writeln('');
            $output->writeln('Add it to auth.json in the consuming project, or set COMPOSER_AUTH in CI:');
            $output->writeln('  '.json_encode(['bearer' => [$key->host => $key->token]], JSON_UNESCAPED_SLASHES));
        }

        if ($key->expiresAt !== null) {
            $output->writeln('Expires '.substr($key->expiresAt, 0, 10).'. Revoke early with "composer vaults:private:keys:revoke '.$key->uuid.'".');
        }

        return self::SUCCESS;
    }
}
