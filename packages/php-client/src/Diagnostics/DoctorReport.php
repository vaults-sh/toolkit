<?php

declare(strict_types=1);

namespace Vaults\Diagnostics;

final readonly class DoctorReport
{
    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $healthy,
    ) {}
}
