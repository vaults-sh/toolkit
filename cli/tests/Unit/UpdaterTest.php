<?php

declare(strict_types=1);

use App\Updater\GithubReleaseStrategy;
use App\Updater\ReleaseLocator;
use App\Updater\UpdateNotifier;
use Humbug\SelfUpdate\Updater;

function locatorAnswering(?array $headers, ?string &$requested = null): ReleaseLocator
{
    return new ReleaseLocator(headResponse: function (string $url) use ($headers, &$requested): ?array {
        $requested = $url;

        return $headers;
    });
}

it('reads the latest version from the release redirect without calling the api', function () {
    $requested = null;
    $locator = locatorAnswering(['HTTP/1.1 302 Found', 'Location: https://github.com/vaults-sh/toolkit/releases/download/0.4.2/vaults'], $requested);

    expect($locator->latestVersion())->toBe('0.4.2')
        ->and($requested)->toBe('https://github.com/vaults-sh/toolkit/releases/latest/download/vaults')
        ->and($locator->downloadUrl('0.4.2'))->toBe('https://github.com/vaults-sh/toolkit/releases/download/0.4.2/vaults');
});

it('returns null when the redirect is missing or the request fails', function () {
    expect(locatorAnswering(['HTTP/1.1 404 Not Found'])->latestVersion())->toBeNull()
        ->and(locatorAnswering(null)->latestVersion())->toBeNull();
});

it('offers the remote version only when it is newer than the running build', function () {
    $updater = new Updater(null, false);

    $strategy = new GithubReleaseStrategy(locatorAnswering(['Location: /releases/download/0.2.0/vaults']));
    $strategy->setCurrentLocalVersion('0.1.0');

    expect($strategy->getCurrentRemoteVersion($updater))->toBe('0.2.0')
        ->and($strategy->getCurrentLocalVersion($updater))->toBe('0.1.0');

    $strategy->setCurrentLocalVersion('0.3.0');

    expect($strategy->getCurrentRemoteVersion($updater))->toBe('0.3.0');
});

it('announces a newer release once a day and stays quiet otherwise', function () {
    $dir = sys_get_temp_dir().'/vaults-update-'.uniqid();
    $cache = $dir.'/update-check.json';
    $calls = 0;
    $locator = new ReleaseLocator(headResponse: function () use (&$calls): array {
        $calls++;

        return ['Location: /releases/download/0.2.0/vaults'];
    });

    $notifier = new UpdateNotifier($locator, $cache, 1_000_000);

    expect($notifier->message('0.1.0'))->toBe('vaults 0.2.0 is available (you have 0.1.0). Run "vaults self-update" to upgrade.')
        ->and($notifier->message('0.2.0'))->toBeNull()
        ->and($calls)->toBe(1)
        ->and(json_decode((string) file_get_contents($cache), true)['latest'])->toBe('0.2.0');

    $later = new UpdateNotifier($locator, $cache, 1_000_000 + UpdateNotifier::CheckInterval);

    expect($later->message('0.1.0'))->not->toBeNull()
        ->and($calls)->toBe(2);
});

it('never checks when disabled or when running from source', function () {
    $calls = 0;
    $locator = new ReleaseLocator(headResponse: function () use (&$calls): array {
        $calls++;

        return ['Location: /releases/download/9.9.9/vaults'];
    });
    $cache = sys_get_temp_dir().'/vaults-update-'.uniqid().'/update-check.json';

    expect((new UpdateNotifier($locator, $cache))->message('dev-main'))->toBeNull();

    putenv(UpdateNotifier::DisableVariable.'=1');

    expect((new UpdateNotifier($locator, $cache))->message('0.1.0'))->toBeNull()
        ->and($calls)->toBe(0);

    putenv(UpdateNotifier::DisableVariable);
});
