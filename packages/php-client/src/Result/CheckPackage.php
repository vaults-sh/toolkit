<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class CheckPackage
{
    public function __construct(
        public string $name,
        public string $version,
        public bool $deposited,
        public ?string $securityStatus,
        public bool $private = false,
        public ?string $privateSource = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['version'] ?? ''),
            (bool) ($data['deposited'] ?? false),
            is_string($data['security_status'] ?? null) ? $data['security_status'] : null,
            (bool) ($data['private'] ?? false),
            is_string($data['private_source'] ?? null) ? $data['private_source'] : null,
        );
    }

    public function privateLabel(): ?string
    {
        if (! $this->private) {
            return null;
        }

        return match ($this->privateSource) {
            'own_repository' => 'your repository',
            'third_party' => 'third-party',
            'shared' => 'shared with you',
            default => 'private',
        };
    }
}
