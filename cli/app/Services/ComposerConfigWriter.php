<?php

declare(strict_types=1);

namespace App\Services;

class ComposerConfigWriter
{
    public function hasRepository(string $directory, string $url): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $repositories = is_array($decoded) && is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];

        foreach ($repositories as $entry) {
            if (is_array($entry) && rtrim((string) ($entry['url'] ?? ''), '/') === rtrim($url, '/')) {
                return true;
            }
        }

        return false;
    }

    public function addRepository(string $directory, string $url): bool
    {
        $command = sprintf(
            'composer config repositories.vaults composer %s --working-dir=%s 2>&1',
            escapeshellarg($url),
            escapeshellarg($directory),
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }
}
