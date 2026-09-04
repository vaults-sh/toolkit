<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Result\DeviceCodePair;
use Vaults\Result\PollResult;
use Vaults\VaultsClient;

final readonly class DeviceFlow
{
    public function __construct(private VaultsClient $client) {}

    public function start(?string $name = null): DeviceCodePair
    {
        return $this->client->createDeviceCode($name);
    }

    /**
     * @param  (callable(int): void)|null  $onPoll
     * @param  (callable(int): void)|null  $sleep
     */
    public function await(DeviceCodePair $pair, ?callable $onPoll = null, ?callable $sleep = null): PollResult
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $deadline = time() + $pair->expiresIn;
        $attempt = 0;

        while (time() < $deadline) {
            $sleep($pair->interval);

            $attempt++;

            if ($onPoll !== null) {
                $onPoll($attempt);
            }

            $result = $this->client->pollDeviceCode($pair->deviceCode);

            if (! $result->isPending()) {
                return $result;
            }
        }

        return new PollResult('expired');
    }
}
