<?php

declare(strict_types=1);

use Vaults\Composer\AuthJson;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-authjson-'.uniqid();
    mkdir($this->dir);
    $this->home = $this->dir.'/home';
    mkdir($this->home);
    putenv('COMPOSER_HOME='.$this->home);
    putenv('COMPOSER_AUTH');
    $this->previousHome = getenv('HOME');
    putenv('HOME='.$this->dir);
});

afterEach(function () {
    putenv('COMPOSER_HOME');
    putenv('COMPOSER_AUTH');
    putenv('HOME='.$this->previousHome);
});

it('finds http-basic and bearer entries for a host, case-insensitively', function () {
    file_put_contents($this->dir.'/auth.json', json_encode([
        'http-basic' => ['Satis.Example.com' => ['username' => 'tom', 'password' => 'hunter2']],
        'bearer' => ['repo.packagist.com' => 'tok'],
    ]));

    $reader = new AuthJson;

    expect($reader->credentialsFor('satis.example.com', $this->dir))->toBe(['type' => 'http-basic', 'username' => 'tom', 'secret' => 'hunter2', 'source' => './auth.json'])
        ->and($reader->credentialsFor('repo.packagist.com', $this->dir))->toBe(['type' => 'bearer', 'username' => null, 'secret' => 'tok', 'source' => './auth.json'])
        ->and($reader->credentialsFor('unknown.example.com', $this->dir))->toBeNull();
});

it('prefers COMPOSER_AUTH, then the project, then the global auth.json', function () {
    putenv('COMPOSER_AUTH='.json_encode(['bearer' => ['satis.example.com' => 'from-env']]));
    file_put_contents($this->dir.'/auth.json', json_encode(['bearer' => ['satis.example.com' => 'from-project', 'other.example.com' => 'project-other']]));
    file_put_contents($this->home.'/auth.json', json_encode(['bearer' => ['other.example.com' => 'global-other', 'global.example.com' => 'global-only']]));

    $reader = new AuthJson;

    expect($reader->credentialsFor('satis.example.com', $this->dir)['secret'])->toBe('from-env')
        ->and($reader->credentialsFor('satis.example.com', $this->dir)['source'])->toBe('COMPOSER_AUTH')
        ->and($reader->credentialsFor('other.example.com', $this->dir)['secret'])->toBe('project-other')
        ->and($reader->credentialsFor('global.example.com', $this->dir)['secret'])->toBe('global-only')
        ->and($reader->credentialsFor('global.example.com', $this->dir)['source'])->toBe('~/home/auth.json');
});
