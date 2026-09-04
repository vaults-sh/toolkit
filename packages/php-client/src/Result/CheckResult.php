<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class CheckResult
{
    /**
     * @param  list<CheckPackage>  $packages
     */
    public function __construct(
        public int $total,
        public int $deposited,
        public int $undeposited,
        public array $packages,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $packages = [];

        if (is_array($data['packages'] ?? null)) {
            $packages = array_values(array_map(
                fn (array $package): CheckPackage => CheckPackage::fromArray($package),
                array_filter($data['packages'], 'is_array'),
            ));
        }

        return new self(
            (int) ($data['total'] ?? 0),
            (int) ($data['deposited'] ?? 0),
            (int) ($data['undeposited'] ?? 0),
            $packages,
        );
    }

    public function isFullyDeposited(): bool
    {
        return $this->undeposited === 0;
    }
}
