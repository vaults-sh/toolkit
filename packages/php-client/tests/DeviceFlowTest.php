<?php

declare(strict_types=1);

use Tests\Support\FakeTransport;
use Vaults\Auth\DeviceFlow;
use Vaults\Result\DeviceCodePair;
use Vaults\Support\FakeClock;
use Vaults\Support\FakeSleeper;
use Vaults\Support\Sleeper;
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

    $sleeper = new FakeSleeper;
    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport), $sleeper);

    $attempts = [];
    $result = $flow->await(pair(), function (int $attempt) use (&$attempts): void {
        $attempts[] = $attempt;
    });

    expect($result->isApproved())->toBeTrue()
        ->and($result->token)->toBe('issued')
        ->and($sleeper->sleeps)->toBe([5, 5, 5])
        ->and($attempts)->toBe([1, 2, 3]);
});

it('stops when the server reports expiry', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'expired']], 410);

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport), new FakeSleeper);

    expect($flow->await(pair())->isExpired())->toBeTrue();
});

it('stops when the sign-in is denied in the browser', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['message' => 'Not Found.'], 404);

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport), new FakeSleeper);

    $result = $flow->await(pair());

    expect($result->isDenied())->toBeTrue()
        ->and($result->isApproved())->toBeFalse()
        ->and($result->token)->toBeNull();
});

it('gives up locally once the pair lifetime has passed', function () {
    $transport = new FakeTransport;
    $transport->queueJson(['data' => ['status' => 'pending']]);
    $transport->queueJson(['data' => ['status' => 'pending']]);

    $clock = new FakeClock(1_000);
    $sleeper = new class($clock) implements Sleeper
    {
        public function __construct(private FakeClock $clock) {}

        public function sleep(int $seconds): void
        {
            $this->clock->advance($seconds);
        }
    };

    $flow = new DeviceFlow(new VaultsClient(null, 'https://vaults.test', $transport), $sleeper, $clock);

    $result = $flow->await(pair(expiresIn: 10, interval: 5));

    expect($result->isExpired())->toBeTrue()
        ->and($transport->requests)->toHaveCount(2)
        ->and($clock->now())->toBe(1_010);
});
