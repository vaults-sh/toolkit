<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Result\DeviceCodePair;
use Vaults\Result\PollResult;
use Vaults\Support\Clock;
use Vaults\Support\NativeSleeper;
use Vaults\Support\Sleeper;
use Vaults\Support\SystemClock;
use Vaults\VaultsClient;

final readonly class DeviceFlow
{
    public function __construct(
        private VaultsClient $client,
        private Sleeper $sleeper = new NativeSleeper,
        private Clock $clock = new SystemClock,
    ) {}

    public function start(?string $name = null): DeviceCodePair
    {
        return $this->client->createDeviceCode($name);
    }

    /**
     * @param  (callable(int): void)|null  $onPoll
     */
    public function await(DeviceCodePair $pair, ?callable $onPoll = null): PollResult
    {
        $deadline = $this->clock->now() + $pair->expiresIn;

        for ($attempt = 1; $this->clock->now() < $deadline; $attempt++) {
            $this->sleeper->sleep($pair->interval);

            if ($onPoll !== null) {
                $onPoll($attempt);
            }

            $result = $this->client->pollDeviceCode($pair->deviceCode);

            if ($result->isTerminal()) {
                return $result;
            }
        }

        return PollResult::expired();
    }
}
