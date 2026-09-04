<?php

declare(strict_types=1);

namespace Vaults\Transport;

interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
