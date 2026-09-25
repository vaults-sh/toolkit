<?php

declare(strict_types=1);

use Vaults\Composer\PrivateRepositoryDetector;
use Vaults\Result\RepositoryCredential;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-detector-'.uniqid();
    mkdir($this->dir);
    putenv('COMPOSER_HOME='.$this->dir.'/nohome');
    putenv('COMPOSER_AUTH');
});

afterEach(function () {
    putenv('COMPOSER_HOME');
});

function detectorLock(): string
{
    return json_encode([
        'packages' => [
            ['name' => 'vendor/lib', 'dist' => ['url' => 'https://api.github.com/repos/vendor/lib/zipball/a']],
            ['name' => 'dedoc/scramble-pro', 'dist' => ['url' => 'https://satis.dedoc.co/dist/dedoc/scramble-pro/0.9.15.zip']],
            ['name' => 'dedoc/scramble-pro-extra', 'dist' => ['url' => 'https://satis.dedoc.co/dist/dedoc/scramble-pro-extra/1.0.0.zip']],
            ['name' => 'acme/local', 'dist' => ['url' => '../packages/local']],
        ],
        'packages-dev' => [
            ['name' => 'spatie/paid', 'dist' => ['url' => 'https://repo.packagist.com:8443/acme/dists/spatie/paid.zip']],
            ['name' => 'nocreds/paid', 'dist' => ['url' => 'https://satis.other.test/dist/x.zip']],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('finds lock hosts the developer has credentials for, grouped with their packages', function () {
    file_put_contents($this->dir.'/auth.json', json_encode([
        'http-basic' => ['satis.dedoc.co' => ['username' => 'tom', 'password' => 'hunter2']],
        'bearer' => ['repo.packagist.com:8443' => 'tok'],
    ]));

    $detected = (new PrivateRepositoryDetector)->detect(detectorLock(), $this->dir, []);

    expect($detected)->toHaveCount(2)
        ->and($detected[0]['host'])->toBe('satis.dedoc.co')
        ->and($detected[0]['packages'])->toBe(['dedoc/scramble-pro', 'dedoc/scramble-pro-extra'])
        ->and($detected[0]['credentials']['type'])->toBe('http-basic')
        ->and($detected[1]['host'])->toBe('repo.packagist.com:8443')
        ->and($detected[1]['credentials']['secret'])->toBe('tok');
});

it('ignores hosts the team already gave Vaults, hosts without local credentials, and non-http dists', function () {
    file_put_contents($this->dir.'/auth.json', json_encode(['http-basic' => ['satis.dedoc.co' => ['username' => 'tom', 'password' => 'hunter2']]]));

    $team = [RepositoryCredential::fromArray(['uuid' => 'c1', 'host' => 'Satis.Dedoc.co', 'type' => 'http-basic'])];

    expect((new PrivateRepositoryDetector)->detect(detectorLock(), $this->dir, $team))->toBe([])
        ->and((new PrivateRepositoryDetector)->detect('not json', $this->dir, []))->toBe([]);
});
