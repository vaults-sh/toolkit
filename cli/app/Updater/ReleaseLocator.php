<?php

declare(strict_types=1);

namespace App\Updater;

use Closure;

final readonly class ReleaseLocator
{
    public const string RepositoryUrl = 'https://github.com/vaults-sh/toolkit';

    public const string AssetName = 'vaults';

    /**
     * @param  (Closure(string): (list<string>|null))|null  $headResponse
     */
    public function __construct(
        private string $repositoryUrl = self::RepositoryUrl,
        private string $assetName = self::AssetName,
        private ?Closure $headResponse = null,
        private float $timeout = 5.0,
    ) {}

    public function latestVersion(): ?string
    {
        $headers = ($this->headResponse ?? $this->head(...))($this->latestUrl());

        if ($headers === null) {
            return null;
        }

        foreach ($headers as $header) {
            if (stripos($header, 'Location:') !== 0) {
                continue;
            }

            if (preg_match('#/releases/download/([0-9]+\.[0-9]+\.[0-9]+)/#', $header, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public function latestUrl(): string
    {
        return rtrim($this->repositoryUrl, '/').'/releases/latest/download/'.$this->assetName;
    }

    public function downloadUrl(string $version): string
    {
        return rtrim($this->repositoryUrl, '/').'/releases/download/'.$version.'/'.$this->assetName;
    }

    /**
     * @return list<string>|null
     */
    private function head(string $url): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'HEAD',
            'follow_location' => 0,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'user_agent' => 'vaults-cli',
        ]]);

        $http_response_header = [];
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return null;
        }

        return array_values($http_response_header);
    }
}
