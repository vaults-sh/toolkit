<?php

declare(strict_types=1);

namespace Vaults\Support;

final class FakeClock implements Clock
{
    public function __construct(private int $now = 0) {}

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
