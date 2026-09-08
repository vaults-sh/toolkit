<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\TokenStore;

class LogoutCommand extends Command
{
    protected $signature = 'logout
        {--team= : Forget a specific team (uuid or name) instead of the current one}
        {--all : Forget every team on this machine}';

    protected $description = 'Remove stored Vaults credentials for the current team, one team, or all teams';

    public function handle(TokenStore $store): int
    {
        if ($this->option('all')) {
            $store->clear();
            $this->info('Logged out of every team.');

            return self::SUCCESS;
        }

        $wanted = $this->option('team');
        $team = is_string($wanted) && $wanted !== '' ? TeamsCommand::find($store, $wanted) : $store->team();

        if ($team === null) {
            if ($store->token() !== null) {
                $store->clear();
                $this->info('Logged out.');

                return self::SUCCESS;
            }

            $this->info('Nothing to log out of.');

            return self::SUCCESS;
        }

        $store->forget($team->uuid);
        $this->info('Logged out of '.($team->name ?? $team->uuid).'.');

        return self::SUCCESS;
    }
}
