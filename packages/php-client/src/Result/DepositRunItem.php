<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class DepositRunItem
{
    public function __construct(
        public string $uuid,
        public string $status,
        public ?string $error,
        public string $package,
        public string $version,
        public string $reference,
        public string $securityStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['status'] ?? ''),
            is_string($data['error'] ?? null) ? $data['error'] : null,
            (string) ($data['package'] ?? ''),
            (string) ($data['version'] ?? ''),
            (string) ($data['reference'] ?? ''),
            (string) ($data['security_status'] ?? 'unknown'),
        );
    }
}
