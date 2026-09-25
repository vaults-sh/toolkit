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
        public ?string $reason = null,
        public ?string $host = null,
        public bool $private = false,
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
            is_string($data['reason'] ?? null) ? $data['reason'] : null,
            is_string($data['host'] ?? null) ? $data['host'] : null,
            (bool) ($data['private'] ?? false),
        );
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isDeposited(): bool
    {
        return $this->status === 'deposited';
    }

    public function needsCredentials(): bool
    {
        return $this->reason === 'credentials_required' || $this->reason === 'credentials_rejected';
    }

    public function needsSourceConnection(): bool
    {
        return $this->reason === 'private_repository';
    }
}
