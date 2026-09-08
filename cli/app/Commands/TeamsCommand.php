<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\TokenStore;
use Vaults\Project\ProjectManifest;
use Vaults\Result\TeamIdentity;

class TeamsCommand extends Command
{
    protected $signature = 'teams {--use= : Make a team (uuid or name) the default for directories without a .vaults.json}';

    protected $description = 'List the teams stored on this machine and which one applies here';

    public function handle(TokenStore $store, ProjectManifest $manifest): int
    {
        $wanted = $this->option('use');

        if (is_string($wanted) && $wanted !== '') {
            $team = self::find($store, $wanted);

            if ($team === null) {
                $this->error('No stored team matches "'.$wanted.'". Run vaults login to add it.');

                return self::FAILURE;
            }

            $store->use($team->uuid);
            $this->info(($team->name ?? $team->uuid).' is now the default team.');
        }

        $teams = $store->teams();

        if ($teams === []) {
            $this->line('No teams stored. Run vaults login to add one.');

            return self::SUCCESS;
        }

        $current = $store->team()?->uuid;
        $projectTeam = $manifest->team((string) getcwd());

        $this->table(['Team', 'Uuid', 'Default', 'This directory'], array_map(fn (TeamIdentity $team): array => [
            $team->name ?? '-',
            $team->uuid,
            $team->uuid === $current ? 'yes' : '',
            $team->uuid === $projectTeam ? 'yes (.vaults.json)' : '',
        ], $teams));

        if ($projectTeam !== null && $store->tokenFor($projectTeam) === null) {
            $this->warn('This directory belongs to team '.$projectTeam.', which is not stored here. Run vaults login for that team.');
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
