<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class PrivateToken
{
    public function __construct(
        public string $token,
        public string $host,
        public string $repositoryUrl,
        public ?int $expiresAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['token'] ?? null) ? $data['token'] : '',
            is_string($data['host'] ?? null) ? $data['host'] : '',
            is_string($data['repository_url'] ?? null) ? $data['repository_url'] : '',
            is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null,
        );
    }
}
