<?php

declare(strict_types=1);

namespace Vaults\Transport;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
