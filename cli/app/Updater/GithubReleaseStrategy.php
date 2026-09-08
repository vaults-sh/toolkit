<?php

declare(strict_types=1);

namespace App\Updater;

use Humbug\SelfUpdate\Exception\HttpRequestException;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;

final class GithubReleaseStrategy implements StrategyInterface
{
    private ?string $localVersion = null;

    public function __construct(private readonly ReleaseLocator $locator = new ReleaseLocator) {}

    public function download(Updater $updater): void
    {
        $remote = $this->locator->latestVersion();

        if ($remote === null) {
            throw new HttpRequestException('Could not determine the latest Vaults release.');
        }

        $url = $this->locator->downloadUrl($remote);
        $bytes = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'vaults-cli']]));

        if ($bytes === false || $bytes === '') {
            throw new HttpRequestException('Request to URL failed: '.$url);
        }

        file_put_contents($updater->getTempPharFile(), $bytes);
    }

    public function getCurrentRemoteVersion(Updater $updater): ?string
    {
        $remote = $this->locator->latestVersion();

        if ($remote === null) {
            return null;
        }

        if ($this->localVersion !== null && version_compare($remote, $this->localVersion, '<=')) {
            return $this->localVersion;
        }

        return $remote;
    }

    public function getCurrentLocalVersion(Updater $updater): ?string
    {
        return $this->localVersion;
    }

    public function setPackageName($name): void {}

    public function setCurrentLocalVersion($version): void
    {
        $this->localVersion = is_string($version) && $version !== '' ? $version : null;
    }
}
