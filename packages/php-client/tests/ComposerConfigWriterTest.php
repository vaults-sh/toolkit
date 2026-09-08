<?php

declare(strict_types=1);

use Vaults\Composer\ComposerConfigWriter;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-writer-'.uniqid();
    mkdir($this->dir);
});

it('detects an already configured repository regardless of trailing slash', function () {
    file_put_contents($this->dir.'/composer.json', json_encode(['repositories' => [['type' => 'composer', 'url' => 'https://private.vaults-edge.net/']]]));

    $writer = new ComposerConfigWriter;

    expect($writer->hasRepository($this->dir, 'https://private.vaults-edge.net'))->toBeTrue()
        ->and($writer->hasRepository($this->dir, 'https://repo.vaults-edge.net/repo/global'))->toBeFalse();
});

it('merges a bearer token into an existing auth.json and creates missing directories', function () {
    $path = $this->dir.'/nested/auth.json';
    $writer = new ComposerConfigWriter;

    expect($writer->writeBearerToken($path, 'private.vaults-edge.net', 'first'))->toBeTrue();

    file_put_contents($path, json_encode(['http-basic' => ['example.com' => ['username' => 'u', 'password' => 'p']], 'bearer' => ['private.vaults-edge.net' => 'first']]));

    $writer->writeBearerToken($path, 'private.vaults-edge.net', 'second');

    $auth = json_decode((string) file_get_contents($path), true);

    expect($auth['bearer']['private.vaults-edge.net'])->toBe('second')
        ->and($auth['http-basic']['example.com']['username'])->toBe('u');
});

it('resolves the global auth path from COMPOSER_HOME first', function () {
    putenv('COMPOSER_HOME='.$this->dir.'/home');

    expect((new ComposerConfigWriter)->globalAuthPath())->toBe($this->dir.'/home/auth.json');

    putenv('COMPOSER_HOME');
});
