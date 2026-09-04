<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class TeamIdentity
{
    public function __construct(
        public ?string $uuid,
        public ?string $name,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['uuid'] ?? null) ? $data['uuid'] : null,
            is_string($data['name'] ?? null) ? $data['name'] : null,
        );
    }
}
