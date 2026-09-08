<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class PrivateKey
{
    /**
     * @param  list<string>|null  $packages
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $projectName,
        public ?array $packages,
        public ?string $expiresAt,
        public ?string $createdAt,
        public ?string $token = null,
        public ?string $host = null,
        public ?string $repositoryUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $token = null, ?string $host = null, ?string $repositoryUrl = null): self
    {
        $project = is_array($data['project'] ?? null) ? $data['project'] : null;
        $packages = is_array($data['packages'] ?? null)
            ? array_values(array_filter($data['packages'], 'is_string'))
            : null;

        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['name'] ?? ''),
            is_string($project['name'] ?? null) ? $project['name'] : null,
            $packages,
            is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
            $token,
            $host,
            $repositoryUrl,
        );
    }

    public function scopeLabel(): string
    {
        $parts = [];

        if ($this->projectName !== null) {
            $parts[] = $this->projectName;
        }

        if ($this->packages !== null && $this->packages !== []) {
            $parts[] = count($this->packages) === 1 ? $this->packages[0] : count($this->packages).' packages';
        }

        return $parts === [] ? 'all private packages' : implode(' · ', $parts);
    }
}
