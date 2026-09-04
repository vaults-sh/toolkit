<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class Project
{
    /**
     * @param  array<string, mixed>  $repositorySnippet
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $description,
        public ?string $repositoryUrl,
        public array $repositorySnippet,
        public bool $repositoryPublished,
        public float $depositPercentage,
        public ?DepositRun $latestRun,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $latestRun = $data['latest_run'] ?? null;

        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['name'] ?? ''),
            is_string($data['description'] ?? null) ? $data['description'] : null,
            is_string($data['repository_url'] ?? null) ? $data['repository_url'] : null,
            is_array($data['repository'] ?? null) ? $data['repository'] : [],
            (bool) ($data['repository_published'] ?? false),
            (float) ($data['deposit_percentage'] ?? 0),
            is_array($latestRun) ? DepositRun::fromArray($latestRun) : null,
        );
    }
}
