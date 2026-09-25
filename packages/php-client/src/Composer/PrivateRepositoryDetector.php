<?php

declare(strict_types=1);

namespace Vaults\Composer;

use Vaults\Result\RepositoryCredential;

final readonly class PrivateRepositoryDetector
{
    public function __construct(private AuthJson $authJson = new AuthJson) {}

    /**
     * Hosts in composer.lock that the developer holds credentials for locally but the team has not
     * given to Vaults yet, with the packages served from each.
     *
     * @param  list<RepositoryCredential>  $teamCredentials
     * @return list<array{host: string, packages: list<string>, credentials: array{type: string, username: string|null, secret: string, source: string}}>
     */
    public function detect(string $composerLock, string $directory, array $teamCredentials): array
    {
        $decoded = json_decode($composerLock, true);

        if (! is_array($decoded)) {
            return [];
        }

        $known = array_map(fn (RepositoryCredential $credential): string => strtolower($credential->host), $teamCredentials);
        $hosts = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($decoded[$section] ?? null) ? $decoded[$section] : [] as $entry) {
                $url = is_array($entry) && is_array($entry['dist'] ?? null) ? ($entry['dist']['url'] ?? null) : null;
                $name = is_array($entry) ? ($entry['name'] ?? null) : null;
                $host = is_string($url) ? $this->hostFor($url) : null;

                if ($host === null || ! is_string($name) || in_array($host, $known, true)) {
                    continue;
                }

                $hosts[$host][] = $name;
            }
        }

        $detected = [];

        foreach ($hosts as $host => $packages) {
            $credentials = $this->authJson->credentialsFor($host, $directory);

            if ($credentials === null) {
                continue;
            }

            $detected[] = ['host' => $host, 'packages' => array_values(array_unique($packages)), 'credentials' => $credentials];
        }

        return $detected;
    }

    private function hostFor(string $url): ?string
    {
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);
        $default = str_starts_with($url, 'http://') ? 80 : 443;

        return strtolower($host).(is_int($port) && $port !== $default ? ':'.$port : '');
    }
}
