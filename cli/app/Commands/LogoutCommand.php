<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\TokenStore;

class LogoutCommand extends Command
{
    protected $signature = 'logout';

    protected $description = 'Remove the stored Vaults credentials';

    public function handle(TokenStore $store): int
    {
        $store->clear();

        $this->info('Logged out.');

        return self::SUCCESS;
    }
}
