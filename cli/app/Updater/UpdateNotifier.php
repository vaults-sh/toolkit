<?php

declare(strict_types=1);

namespace App\Updater;

use Vaults\Auth\TokenStore;

final readonly class UpdateNotifier
{
    public const string DisableVariable = 'VAULTS_NO_UPDATE_CHECK';

    public const int CheckInterval = 86400;

    public function __construct(
        private ReleaseLocator $locator,
        private ?string $cachePath = null,
        private ?int $now = null,
    ) {}

    public function message(string $currentVersion): ?string
    {
        if (getenv(self::DisableVariable) !== false || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $currentVersion)) {
            return null;
        }

        $latest = $this->latestVersion();

        if ($latest === null || version_compare($latest, $currentVersion, '<=')) {
            return null;
        }

        return 'vaults '.$latest.' is available (you have '.$currentVersion.'). Run "vaults self-update" to upgrade.';
    }

    private function latestVersion(): ?string
    {
        $now = $this->now ?? time();
        $path = $this->cachePath ?? dirname(TokenStore::defaultPath()).DIRECTORY_SEPARATOR.'update-check.json';
        $cached = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($cached) && is_int($cached['checked_at'] ?? null) && $now - $cached['checked_at'] < self::CheckInterval) {
            return is_string($cached['latest'] ?? null) ? $cached['latest'] : null;
        }

        $latest = $this->locator->latestVersion();

        $directory = dirname($path);

        if (is_dir($directory) || @mkdir($directory, 0755, true) || is_dir($directory)) {
            @file_put_contents($path, json_encode(['checked_at' => $now, 'latest' => $latest]));
        }

        return $latest;
    }
}
