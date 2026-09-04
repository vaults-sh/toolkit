<?php

declare(strict_types=1);

namespace App\Services;

class EdgeProbe
{
    /**
     * @return list<string>
     */
    public function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_CNAME | DNS_A);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['target'] ?? $record['ip'] ?? null,
            $records,
        )));
    }

    public function healthCheck(string $hostname): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents('https://'.$hostname.'/health/check.txt', false, $context);

        return is_string($body) && str_contains($body, 'status=ok');
    }
}
