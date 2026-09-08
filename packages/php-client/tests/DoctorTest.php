<?php

declare(strict_types=1);

use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\Diagnostics\Doctor;
use Vaults\Diagnostics\EdgeProbe;
use Vaults\VaultsClient;

function fakeProbe(array $answers, bool $healthy): EdgeProbe
{
    return new class($answers, $healthy) extends EdgeProbe
    {
        public function __construct(private array $answers, private bool $healthy) {}

        public function resolve(string $hostname): array
        {
            return $this->answers;
        }

        public function healthCheck(string $hostname): bool
        {
            return $this->healthy;
        }
    };
}

it('reports every check healthy when the api, token and edges respond', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'ok']]);
    $transport->queueJson(['data' => ['team' => ['uuid' => 'u', 'name' => 'Acme']]]);
    $dir = sys_get_temp_dir().'/vaults-doctor-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/composer.lock', '{}');
    $store = new TokenStore($dir.'/config.json');
    $store->save('stored');

    $report = (new Doctor(new VaultsClient('stored', 'https://vaults.test', $transport), $store, fakeProbe(['edge.example'], true)))->run($dir);

    expect($report->healthy)->toBeTrue()
        ->and($report->rows[0])->toBe(['Vaults API', '✓'])
        ->and($report->rows[1])->toBe(['Authentication', '✓ team: Acme'])
        ->and($report->rows[2])->toBe(['composer.lock present', '✓'])
        ->and($report->rows)->toHaveCount(3 + 2 * count(Doctor::EdgeHosts));
});

it('is unhealthy when an edge does not resolve and shows the login hint when logged out', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'ok']]);
    $dir = sys_get_temp_dir().'/vaults-doctor-'.uniqid();
    mkdir($dir);
    $store = new TokenStore($dir.'/config.json');

    $report = (new Doctor(new VaultsClient(null, 'https://vaults.test', $transport), $store, fakeProbe([], true), 'run composer vaults:login'))->run($dir);

    expect($report->healthy)->toBeFalse()
        ->and($report->rows[1])->toBe(['Authentication', '- not logged in (run composer vaults:login)'])
        ->and($report->rows[3][1])->toBe('✗ no answer');
});
