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

    public function addPrivateRepository(string $directory, string $url): bool
    {
        $definition = json_encode([
            'type' => 'composer',
            'url' => $url,
            'canonical' => false,
        ], JSON_THROW_ON_ERROR);

        $command = sprintf(
            'composer config repositories.vaults-private %s --working-dir=%s 2>&1',
            escapeshellarg($definition),
            escapeshellarg($directory),
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    public function writeBearerToken(string $authPath, string $host, string $token): bool
    {
        $existing = is_file($authPath)
            ? json_decode((string) file_get_contents($authPath), true)
            : [];

        $config = is_array($existing) ? $existing : [];
        $bearer = is_array($config['bearer'] ?? null) ? $config['bearer'] : [];
        $bearer[$host] = $token;
        $config['bearer'] = $bearer;

        $directory = dirname($authPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return file_put_contents(
            $authPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        ) !== false;
    }

    public function globalAuthPath(): string
    {
        $composerHome = getenv('COMPOSER_HOME');

        if (is_string($composerHome) && $composerHome !== '') {
            return rtrim($composerHome, '/').'/auth.json';
        }

        $home = getenv('HOME');
        $base = is_string($home) && $home !== '' ? rtrim($home, '/') : (string) getcwd();

        return is_dir($base.'/.config/composer')
            ? $base.'/.config/composer/auth.json'
            : $base.'/.composer/auth.json';
    }
}
