<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class DepositRun
{
    /**
     * @param  list<DepositRunItem>|null  $items
     */
    public function __construct(
        public string $uuid,
        public string $status,
        public int $packagesTotal,
        public int $packagesDeposited,
        public int $packagesFailed,
        public int $packagesSkipped,
        public int $packagesPrivate,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?array $items = null,
        public ?int $packagesAlreadyDeposited = null,
        public bool $analysed = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $items = null;

        if (is_array($data['items'] ?? null)) {
            $items = array_values(array_map(
                fn (array $item): DepositRunItem => DepositRunItem::fromArray($item),
                array_filter($data['items'], 'is_array'),
            ));
        }

        return new self(
            (string) ($data['uuid'] ?? ''),
            (string) ($data['status'] ?? ''),
            (int) ($data['packages_total'] ?? 0),
            (int) ($data['packages_deposited'] ?? 0),
            (int) ($data['packages_failed'] ?? 0),
            (int) ($data['packages_skipped'] ?? 0),
            (int) ($data['packages_private'] ?? 0),
            is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            $items,
            is_int($data['packages_already_deposited'] ?? null) ? $data['packages_already_deposited'] : null,
            ! array_key_exists('packages_already_deposited', $data) || $data['packages_already_deposited'] !== null,
        );
    }

    public function packagesToDeposit(): int
    {
        return max(0, $this->packagesTotal - ($this->packagesAlreadyDeposited ?? 0));
    }

    public function packagesChecked(): int
    {
        return max(0, min($this->packagesTotal, $this->packagesDeposited + $this->packagesSkipped + $this->packagesFailed));
    }

    public function packagesProcessed(): int
    {
        $processed = $this->packagesDeposited + $this->packagesSkipped + $this->packagesFailed - ($this->packagesAlreadyDeposited ?? 0);

        return max(0, min($this->packagesToDeposit(), $processed));
    }

    public function packagesNewlyDeposited(): int
    {
        return max(0, $this->packagesDeposited - ($this->packagesAlreadyDeposited ?? 0));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * @return list<DepositRunItem>
     */
    public function failedItems(): array
    {
        return array_values(array_filter($this->items ?? [], fn (DepositRunItem $item): bool => $item->isFailed()));
    }

    /**
     * @return list<DepositRunItem>
     */
    public function skippedItems(): array
    {
        return array_values(array_filter($this->items ?? [], fn (DepositRunItem $item): bool => $item->isSkipped()));
    }

    /**
     * @return list<DepositRunItem>
     */
    public function depositedPrivateItems(): array
    {
        return array_values(array_filter($this->items ?? [], fn (DepositRunItem $item): bool => $item->isDeposited() && $item->private));
    }

    /**
     * @return list<string>
     */
    public function hostsNeedingCredentials(): array
    {
        $hosts = [];

        foreach ($this->failedItems() as $item) {
            if ($item->needsCredentials() && $item->host !== null) {
                $hosts[$item->host] = true;
            }
        }

        return array_keys($hosts);
    }

    public function coverablePackages(): int
    {
        return max(0, $this->packagesTotal - $this->packagesPrivate);
    }

    public function depositPercentage(): int
    {
        $coverable = $this->coverablePackages();

        if ($coverable === 0) {
            return $this->packagesTotal > 0 ? 100 : 0;
        }

        return (int) floor($this->packagesDeposited / $coverable * 100);
    }
}
