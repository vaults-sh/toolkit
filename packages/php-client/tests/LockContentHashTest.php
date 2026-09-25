<?php

declare(strict_types=1);

use Vaults\Composer\LockContentHash;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-lockhash-'.uniqid();
    mkdir($this->dir);
});

it('recomputes the content hash the way Composer does', function () {
    $composer = json_encode(['name' => 'acme/app', 'require' => ['a/b' => '^1'], 'repositories' => [['type' => 'composer', 'url' => 'https://x']], 'config' => ['platform' => ['php' => '8.4'], 'sort-packages' => true], 'scripts' => ['x' => 'y']]);
    $expected = md5((string) json_encode(['config' => ['platform' => ['php' => '8.4']], 'name' => 'acme/app', 'repositories' => [['type' => 'composer', 'url' => 'https://x']], 'require' => ['a/b' => '^1']]));

    expect((new LockContentHash)->contentHash($composer))->toBe($expected);
});

it('rewrites composer.lock in place only when composer.json changed the hash', function () {
    file_put_contents($this->dir.'/composer.json', json_encode(['name' => 'acme/app']));
    file_put_contents($this->dir.'/composer.lock', '{"content-hash": "0000000000000000000000000000dead", "packages": []}');

    $hash = new LockContentHash;

    expect($hash->refreshFile($this->dir))->toBeTrue()
        ->and((string) file_get_contents($this->dir.'/composer.lock'))->toContain('"content-hash": "'.$hash->contentHash(json_encode(['name' => 'acme/app'])).'"')
        ->and($hash->refreshFile($this->dir))->toBeFalse()
        ->and($hash->refreshFile($this->dir.'/missing'))->toBeFalse();
});
