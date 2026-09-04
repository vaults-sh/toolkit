<?php

declare(strict_types=1);

namespace Vaults\Support;

final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
