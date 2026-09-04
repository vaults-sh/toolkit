<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\EdgeProbe;
use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\TokenStore;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class DoctorCommand extends Command
{
    protected $signature = 'doctor';

    protected $description = 'Diagnose connectivity to the Vaults API and edge infrastructure';

    public function handle(VaultsClient $client, TokenStore $store, EdgeProbe $probe): int
    {
        $rows = [];
        $healthy = true;

        $rows[] = $this->probeApi($client, $healthy);
        $rows[] = $this->probeAuth($client, $store, $healthy);
        $rows[] = ['composer.lock present', is_file((string) getcwd().'/composer.lock') ? '✓' : '✗ (not a composer project?)'];

        foreach (['dist.vaults-edge.net', 'repo.vaults-edge.net'] as $hostname) {
            $answers = $probe->resolve($hostname);
            $resolved = $answers !== [];
            $served = $resolved && $probe->healthCheck($hostname);
            $healthy = $healthy && $served;

            $rows[] = ['DNS '.$hostname, $resolved ? '✓ '.implode(', ', array_slice($answers, 0, 2)) : '✗ no answer'];
            $rows[] = ['Edge health '.$hostname, $served ? '✓' : '✗'];
        }

        $this->table(['Check', 'Result'], $rows);

        if (! $healthy) {
            $this->warn('Some checks failed.');

            return self::FAILURE;
        }

        $this->info('Everything looks healthy.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeApi(VaultsClient $client, bool &$healthy): array
    {
        try {
            $ok = $client->ping();
        } catch (VaultsException) {
            $ok = false;
        }

        $healthy = $healthy && $ok;

        return ['Vaults API', $ok ? '✓' : '✗ unreachable'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeAuth(VaultsClient $client, TokenStore $store, bool &$healthy): array
    {
        if ($store->token() === null) {
            return ['Authentication', '- not logged in (run vaults login)'];
        }

        try {
            $team = $client->whoami();

            return ['Authentication', '✓ team: '.($team->name ?? 'unknown')];
        } catch (VaultsException) {
            $healthy = false;

            return ['Authentication', '✗ stored token was rejected'];
        }
    }
}
