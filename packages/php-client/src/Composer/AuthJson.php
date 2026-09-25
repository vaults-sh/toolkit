<?php

declare(strict_types=1);

namespace Vaults\Composer;

final class AuthJson
{
    /**
     * @return array{type: string, username: string|null, secret: string, source: string}|null
     */
    public function credentialsFor(string $host, string $directory): ?array
    {
        $host = strtolower($host);

        foreach ($this->sources($directory) as $label => $config) {
            $basic = is_array($config['http-basic'] ?? null) ? $config['http-basic'] : [];
            $bearer = is_array($config['bearer'] ?? null) ? $config['bearer'] : [];

            foreach ($basic as $candidate => $entry) {
                if (strtolower((string) $candidate) === $host && is_array($entry) && is_string($entry['username'] ?? null) && is_string($entry['password'] ?? null)) {
                    return ['type' => 'http-basic', 'username' => $entry['username'], 'secret' => $entry['password'], 'source' => $label];
                }
            }

            foreach ($bearer as $candidate => $token) {
                if (strtolower((string) $candidate) === $host && is_string($token) && $token !== '') {
                    return ['type' => 'bearer', 'username' => null, 'secret' => $token, 'source' => $label];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sources(string $directory): array
    {
        $sources = [];

        $environment = getenv('COMPOSER_AUTH');

        if (is_string($environment) && $environment !== '') {
            $decoded = json_decode($environment, true);

            if (is_array($decoded)) {
                $sources['COMPOSER_AUTH'] = $decoded;
            }
        }

        $projectPath = $directory.DIRECTORY_SEPARATOR.'auth.json';
        $globalPath = (new ComposerConfigWriter)->globalAuthPath();

        foreach ([$projectPath, $globalPath] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                $sources[$path] = $decoded;
            }
        }

        return $sources;
    }
}
