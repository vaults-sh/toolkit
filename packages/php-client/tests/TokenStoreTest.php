<?php

declare(strict_types=1);

use Vaults\Auth\TokenStore;
use Vaults\Result\TeamIdentity;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/vaults-client-tests/'.uniqid().'/config.json';
    putenv(TokenStore::EnvVariable);
});

afterEach(function () {
    putenv(TokenStore::EnvVariable);
});

it('round trips a token and team', function () {
    $store = new TokenStore($this->path);

    $store->save('secret-token', new TeamIdentity('team-uuid', 'Cranbri'));

    expect($store->token())->toBe('secret-token')
        ->and($store->team()?->name)->toBe('Cranbri')
        ->and(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0600');

    $store->clear();

    expect($store->token())->toBeNull();
});

it('prefers the environment variable over the stored token', function () {
    $store = new TokenStore($this->path);
    $store->save('stored-token');

    putenv(TokenStore::EnvVariable.'=env-token');

    expect($store->token())->toBe('env-token');
});

it('returns null when nothing is stored', function () {
    expect((new TokenStore($this->path))->token())->toBeNull();
});
