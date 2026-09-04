<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class ProtectionRun
{
    /**
     * @param  list<ProtectionRunItem>|null  $items
     */
    public function __construct(
        public string $uuid,
        public string $status,
        public int $packagesTotal,
        public int $packagesProtected,
        public int $packagesFailed,
        public int $packagesSkipped,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?array $items = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $items = null;

        if (is_array($data['items'] ?? null)) {
            $items = array_values(array_map(
                fn (array $item): ProtectionRunItem => ProtectionRunItem::fromArray($item),
                array_filter($data['items'], 'is_array'),
            ));
        }

        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['status'] ?? ''),
            (int) ($data['packages_total'] ?? 0),
            (int) ($data['packages_protected'] ?? 0),
            (int) ($data['packages_failed'] ?? 0),
            (int) ($data['packages_skipped'] ?? 0),
            is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            $items,
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
