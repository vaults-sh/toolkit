<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Sleep;
use Vaults\Support\Sleeper;

final class LaravelSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            Sleep::for($seconds)->seconds();
        }
    }
}
