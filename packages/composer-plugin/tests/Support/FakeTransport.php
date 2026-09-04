<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Vaults\Transport\HttpRequest;
use Vaults\Transport\HttpResponse;
use Vaults\Transport\Transport;

final class FakeTransport implements Transport
{
    /** @var list<HttpResponse> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function queueJson(array $body, int $status = 200): self
    {
        $this->queue[] = new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR));

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        $response = array_shift($this->queue);

        if ($response === null) {
            throw new RuntimeException('No queued response for '.$request->method.' '.$request->url);
        }

        return $response;
    }
}
