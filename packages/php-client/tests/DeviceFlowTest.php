<?php

declare(strict_types=1);

use Tests\Support\FakeTransport;
use Vaults\Auth\DeviceFlow;
use Vaults\Result\DeviceCodePair;
use Vaults\VaultsClient;

function pair(int $expiresIn = 900, int $interval = 5): DeviceCodePair
{
    return new DeviceCodePair('plain-code', 'ABCD-EFGH', 'https://vaults.test/device', 'https://vaults.test/device?code=ABCD-EFGH', $expiresIn, $interval);
}

it('polls until approval', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'pending']]);
    $transport->queueJson(['data' => ['status' => 'pending']]);
    $transport->queueJson(['data' => ['status' => 'approved', 'token' => 'issued', 'team' => ['uuid' => 'u', 'name' => 'n']]]);

    $sleeps = [];
    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport));

    $result = $flow->await(pair(), sleep: function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    expect($result->isApproved())->toBeTrue()
        ->and($result->token)->toBe('issued')
        ->and($sleeps)->toBe([5, 5, 5]);
});

it('stops when the server reports expiry', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'expired']], 410);

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport));

    expect($flow->await(pair(), sleep: fn () => null)->isExpired())->toBeTrue();
});

it('stops when the sign-in is denied in the browser', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['message' => 'Not Found.'], 404);

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport));

    $result = $flow->await(pair(), sleep: fn () => null);

    expect($result->isDenied())->toBeTrue()
        ->and($result->isApproved())->toBeFalse()
        ->and($result->token)->toBeNull();
});

it('gives up locally once the pair lifetime has passed', function () {
    $transport = new FakeTransport;

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport));

    $result = $flow->await(pair(expiresIn: 0), sleep: fn () => null);

    expect($result->isExpired())->toBeTrue()
        ->and($transport->requests)->toBeEmpty();
});
