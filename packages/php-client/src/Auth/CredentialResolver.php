<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Project\ProjectManifest;

final readonly class CredentialResolver
{
    public function __construct(
        private TokenStore $store,
        private ProjectManifest $manifest = new ProjectManifest,
    ) {}

    public function resolve(string $directory, ?string $teamUuid = null): ?Credentials
    {
        $fromEnv = getenv(TokenStore::EnvVariable);

        if (is_string($fromEnv) && $fromEnv !== '') {
            return new Credentials($fromEnv, null);
        }

        $wanted = $teamUuid ?? $this->manifest->team($directory);

        if ($wanted !== null) {
            $token = $this->store->tokenFor($wanted);

            return $token === null ? null : new Credentials($token, $this->store->identity($wanted));
        }

        $token = $this->store->token();

        return $token === null ? null : new Credentials($token, $this->store->team());
    }

    public function missingTeam(string $directory, ?string $teamUuid = null): ?string
    {
        $wanted = $teamUuid ?? $this->manifest->team($directory);

        return $wanted !== null && $this->store->tokenFor($wanted) === null ? $wanted : null;
    }
}
