<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class PollResult
{
    public function __construct(
        public string $status,
        public ?string $token = null,
        public ?TeamIdentity $team = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $team = $data['team'] ?? null;

        return new self(
            (string) ($data['status'] ?? 'pending'),
            is_string($data['token'] ?? null) ? $data['token'] : null,
            is_array($team) ? TeamIdentity::fromArray($team) : null,
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }
}
