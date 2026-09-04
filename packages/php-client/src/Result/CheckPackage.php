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
        );
    }
}
