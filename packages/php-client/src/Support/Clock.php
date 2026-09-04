<?php

declare(strict_types=1);

namespace Vaults\Support;

interface Clock
{
    public function now(): int;
}
