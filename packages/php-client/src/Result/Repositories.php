<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class Repositories
{
    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $private
     */
    public function __construct(
        public array $global,
        public array $private,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['global'] ?? null) ? $data['global'] : [],
            is_array($data['private'] ?? null) ? $data['private'] : [],
        );
    }

    public function globalUrl(): ?string
    {
        $url = $this->global['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
