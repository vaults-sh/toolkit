<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class RepositoryCredential
{
    public function __construct(
        public string $uuid,
        public string $host,
        public string $type,
        public ?string $username,
        public ?string $lastUsedAt,
        public ?string $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['host'] ?? ''),
            (string) ($data['type'] ?? ''),
            is_string($data['username'] ?? null) ? $data['username'] : null,
            is_string($data['last_used_at'] ?? null) ? $data['last_used_at'] : null,
            is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
        );
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'http-basic' => 'http-basic'.($this->username !== null ? ' ('.$this->username.')' : ''),
            'bearer' => 'bearer',
            default => $this->type,
        };
    }
}
