<?php

declare(strict_types=1);

namespace Vaults\Support;

final class NativeSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
