<?php

declare(strict_types=1);

namespace Vaults\Transport;

use Vaults\Exception\NetworkException;

final readonly class StreamTransport implements Transport
{
    public function __construct(private int $timeout = 30) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $headerLines = [];

        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $headerLines),
                'content' => $request->body ?? '',
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($request->url, false, $context);

        if ($body === false) {
            throw new NetworkException('Could not reach '.$request->url.'.');
        }

        return new HttpResponse($this->statusFrom($http_response_header), $body);
    }

    /**
     * @param  list<string>  $responseHeaders
     */
    private function statusFrom(array $responseHeaders): int
    {
        $status = 0;

        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
