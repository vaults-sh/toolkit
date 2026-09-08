<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\TokenStore;
use Vaults\Diagnostics\Doctor;
use Vaults\Diagnostics\EdgeProbe;
use Vaults\VaultsClient;

class DoctorCommand extends Command
{
    protected $signature = 'doctor';

    protected $description = 'Diagnose connectivity to the Vaults API and edge infrastructure';

    public function handle(VaultsClient $client, TokenStore $store, EdgeProbe $probe): int
    {
        $report = (new Doctor($client, $store, $probe))->run((string) getcwd());

        $this->table(['Check', 'Result'], $report->rows);

        if (! $report->healthy) {
            $this->warn('Some checks failed.');

            return self::FAILURE;
        }

        $this->info('Everything looks healthy.');

        return self::SUCCESS;
    }
}
