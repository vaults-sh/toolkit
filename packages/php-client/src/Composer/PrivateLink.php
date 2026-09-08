<?php

declare(strict_types=1);

namespace Vaults\Composer;

use Vaults\Result\PrivateKey;
use Vaults\VaultsClient;

final readonly class PrivateLink
{
    public function __construct(private VaultsClient $client) {}

    public static function defaultKeyName(): string
    {
        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'developer machine';
    }

    public function issueKey(string $name, int $expiresInDays): PrivateKey
    {
        $key = $this->client->createPrivateKey($name, null, [], $expiresInDays);

        foreach ($this->client->listPrivateKeys() as $existing) {
            if ($existing->uuid !== $key->uuid && $existing->name === $name && $existing->projectName === null && $existing->packages === null) {
                $this->client->revokePrivateKey($existing->uuid);
            }
        }

        return $key;
    }
}
