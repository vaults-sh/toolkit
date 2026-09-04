<?php

declare(strict_types=1);

use Tests\Support\FakeTransport;
use Vaults\Exception\ApiException;
use Vaults\Exception\AuthenticationException;
use Vaults\VaultsClient;

function fakeClient(FakeTransport $transport, ?string $token = 'test-token'): VaultsClient
{
    return new VaultsClient($token, 'https://vaults.test', $transport, 'https://auth.vaults.test');
}

it('pings the api', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'ok']]);

    expect(fakeClient($transport)->ping())->toBeTrue()
        ->and($transport->lastRequest()->url)->toBe('https://vaults.test/api/v1/ping')
        ->and($transport->lastRequest()->headers['Authorization'])->toBe('Bearer test-token');
});

it('resolves the team identity', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['team' => ['uuid' => 'team-uuid', 'name' => 'Cranbri']]]);

    $team = fakeClient($transport)->whoami();

    expect($team->uuid)->toBe('team-uuid')
        ->and($team->name)->toBe('Cranbri');
});

it('creates a device code without auth', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => [
        'device_code' => 'plain-device-code',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://vaults.test/device',
        'verification_uri_complete' => 'https://vaults.test/device?code=ABCD-EFGH',
        'expires_in' => 900,
        'interval' => 5,
    ]], 201);

    $pair = fakeClient($transport, null)->createDeviceCode('my-laptop');

    expect($pair->userCode)->toBe('ABCD-EFGH')
        ->and($pair->interval)->toBe(5)
        ->and($transport->lastRequest()->url)->toBe('https://auth.vaults.test/api/v1/device-codes')
        ->and($transport->lastRequest()->headers)->not->toHaveKey('Authorization')
        ->and($transport->lastRequest()->body)->toBe('{"name":"my-laptop"}');
});

it('maps device poll states including expiry', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'pending']]);
    $transport->queueJson(['data' => ['status' => 'approved', 'token' => 'issued-token', 'team' => ['uuid' => 'u', 'name' => 'n']]]);
    $transport->queueJson(['data' => ['status' => 'expired']], 410);

    $client = fakeClient($transport, null);

    expect($client->pollDeviceCode('x')->isPending())->toBeTrue()
        ->and($client->pollDeviceCode('x')->token)->toBe('issued-token')
        ->and($client->pollDeviceCode('x')->isExpired())->toBeTrue();
});

it('lists projects with latest runs', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => [[
        'uuid' => 'project-uuid',
        'name' => 'Vaults App',
        'description' => null,
        'repository_url' => null,
        'repository' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        'repository_published' => true,
        'deposit_percentage' => 98.5,
        'latest_run' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 240],
    ]]]);

    $projects = fakeClient($transport)->listProjects();

    expect($projects)->toHaveCount(1)
        ->and($projects[0]->name)->toBe('Vaults App')
        ->and($projects[0]->depositPercentage)->toBe(98.5)
        ->and($projects[0]->latestRun?->status)->toBe('completed');
});

it('starts a deposit run and reads it back with items', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 2]], 202);
    $transport->queueJson(['data' => [
        'uuid' => 'run-uuid',
        'status' => 'completed',
        'packages_total' => 2,
        'packages_deposited' => 2,
        'items' => [
            ['uuid' => 'i1', 'status' => 'deposited', 'error' => null, 'package' => 'a/b', 'version' => 'v1.0.0', 'reference' => 'ref', 'security_status' => 'clear'],
        ],
    ]]);

    $client = fakeClient($transport);
    $run = $client->deposit('project-uuid', '{"packages":[]}');

    expect($run->uuid)->toBe('run-uuid')
        ->and($run->isFinished())->toBeFalse();

    $finished = $client->getRun('run-uuid');

    expect($finished->isFinished())->toBeTrue()
        ->and($finished->items)->toHaveCount(1)
        ->and($finished->items[0]->securityStatus)->toBe('clear');
});

it('runs a deposit check', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => [
        'total' => 2,
        'deposited' => 1,
        'undeposited' => 1,
        'packages' => [
            ['name' => 'a/b', 'version' => 'v1.0.0', 'deposited' => true, 'security_status' => 'clear'],
            ['name' => 'c/d', 'version' => 'v2.0.0', 'deposited' => false, 'security_status' => null],
        ],
    ]]);

    $check = fakeClient($transport)->depositCheck('project-uuid', '{}');

    expect($check->isFullyDeposited())->toBeFalse()
        ->and($check->packages[0]->deposited)->toBeTrue()
        ->and($check->packages[1]->securityStatus)->toBeNull();
});

it('fetches the rewritten lock with repository snippets', function () {
    $transport = new FakeTransport;
    $transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global', 'canonical' => false],
        ],
    ]);

    $lock = fakeClient($transport)->getRewrittenLock('run-uuid');

    expect($lock->composerLock)->toBe('{"packages":[]}')
        ->and($lock->globalRepository['canonical'])->toBeFalse();
});

it('throws typed exceptions for auth and api failures', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['message' => 'Unauthenticated.'], 401);
    $transport->queueJson(['message' => 'Nope'], 422);

    $client = fakeClient($transport);

    expect(fn () => $client->whoami())->toThrow(AuthenticationException::class, 'Unauthenticated.')
        ->and(fn () => $client->whoami())->toThrow(ApiException::class, 'Nope');
});

it('prefers an explicit base url over the default', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'ok']]);

    (new VaultsClient(null, 'https://custom.test/', $transport))->ping();

    expect($transport->lastRequest()->url)->toBe('https://custom.test/api/v1/ping');
});
