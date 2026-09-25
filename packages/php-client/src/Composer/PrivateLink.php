<?php

declare(strict_types=1);

namespace Vaults\Composer;

use RuntimeException;
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

    /**
     * Issues a key, adds the private repository to composer.json when missing, and writes the key
     * to auth.json. Returns the key and whether the repository was newly added.
     *
     * @return array{key: PrivateKey, repositoryAdded: bool}
     *
     * @throws RuntimeException when composer.json or auth.json cannot be written
     */
    public function wire(ComposerConfigWriter $writer, string $directory, string $authPath, string $name, int $expiresInDays): array
    {
        $key = $this->issueKey($name, $expiresInDays);

        if ($key->token === null || $key->host === null || $key->repositoryUrl === null) {
            throw new RuntimeException('The API did not return a key value.');
        }

        $repositoryAdded = false;

        if (! $writer->hasRepository($directory, $key->repositoryUrl)) {
            if (! $writer->addPrivateRepository($directory, $key->repositoryUrl)) {
                throw new RuntimeException('Could not update composer.json. Add this repository manually: { "type": "composer", "url": "'.$key->repositoryUrl.'", "canonical": false }');
            }

            $repositoryAdded = true;
        }

        if (! $writer->writeBearerToken($authPath, $key->host, $key->token)) {
            throw new RuntimeException('Could not write the access key to '.$authPath.'.');
        }

        return ['key' => $key, 'repositoryAdded' => $repositoryAdded];
    }
}
