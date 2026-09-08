<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Result\TeamIdentity;

final readonly class Credentials
{
    public function __construct(
        public string $token,
        public ?TeamIdentity $team,
    ) {}
}
