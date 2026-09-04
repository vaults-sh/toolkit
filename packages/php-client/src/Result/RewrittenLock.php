<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class RewrittenLock
{
    /**
     * @param  array<string, mixed>  $projectRepository
     * @param  array<string, mixed>  $globalRepository
     */
    public function __construct(
        public string $composerLock,
        public array $projectRepository,
        public array $globalRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $repositories = is_array($data['repositories'] ?? null) ? $data['repositories'] : [];

        return new self(
            (string) ($data['composer_lock'] ?? ''),
            is_array($repositories['project'] ?? null) ? $repositories['project'] : [],
            is_array($repositories['global'] ?? null) ? $repositories['global'] : [],
        );
    }
}
