<?php

declare(strict_types=1);

namespace Vaults\Support;

interface Sleeper
{
    public function sleep(int $seconds): void;
}
