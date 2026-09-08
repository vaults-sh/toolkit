<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Auth\TokenStore;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Project\ProjectManifest;
use Vaults\Result\TeamIdentity;

final class TeamsCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:teams')
            ->setDescription('List the teams stored on this machine and which one applies here')
            ->addOption('use', null, InputOption::VALUE_REQUIRED, 'Make a team (uuid or name) the default for directories without a .vaults.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $wanted = $input->getOption('use');

        if (is_string($wanted) && $wanted !== '') {
            $team = self::find($store, $wanted);

            if ($team === null) {
                $output->writeln('<error>No stored team matches "'.$wanted.'". Run "composer vaults:login" to add it.</error>');

                return self::FAILURE;
            }

            $store->use($team->uuid);
            $output->writeln('<info>'.($team->name ?? $team->uuid).' is now the default team.</info>');
        }

        $teams = $store->teams();

        if ($teams === []) {
            $output->writeln('No teams stored. Run "composer vaults:login" to add one.');

            return self::SUCCESS;
        }

        $current = $store->team()?->uuid;
        $projectTeam = (new ProjectManifest)->team($this->directory());

        (new Table($output))
            ->setHeaders(['Team', 'Uuid', 'Default', 'This directory'])
            ->setRows(array_map(fn (TeamIdentity $team): array => [
                $team->name ?? '-',
                $team->uuid,
                $team->uuid === $current ? 'yes' : '',
                $team->uuid === $projectTeam ? 'yes (.vaults.json)' : '',
            ], $teams))
            ->render();

        if ($projectTeam !== null && $store->tokenFor($projectTeam) === null) {
            $output->writeln('<comment>This directory belongs to team '.$projectTeam.', which is not stored here. Run "composer vaults:login" for that team.</comment>');
        }

        return self::SUCCESS;
    }

    public static function find(TokenStore $store, string $wanted): ?TeamIdentity
    {
        foreach ($store->teams() as $team) {
            if ($team->uuid === $wanted || strcasecmp((string) $team->name, $wanted) === 0) {
                return $team;
            }
        }

        return null;
    }
}
