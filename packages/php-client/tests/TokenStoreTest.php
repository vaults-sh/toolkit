<?php

declare(strict_types=1);

use Vaults\Auth\CredentialResolver;
use Vaults\Auth\TokenStore;
use Vaults\Project\ProjectManifest;
use Vaults\Result\TeamIdentity;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/vaults-client-tests/'.uniqid();
    mkdir($this->dir, 0755, true);
    $this->path = $this->dir.'/config.json';
    putenv(TokenStore::EnvVariable);
});

afterEach(function () {
    putenv(TokenStore::EnvVariable);
});

it('stores one token per team and treats the latest login as current', function () {
    $store = new TokenStore($this->path);

    $store->save('acme-token', new TeamIdentity('acme-uuid', 'Acme'));
    $store->save('globex-token', new TeamIdentity('globex-uuid', 'Globex'));

    expect($store->token())->toBe('globex-token')
        ->and($store->team()?->name)->toBe('Globex')
        ->and($store->tokenFor('acme-uuid'))->toBe('acme-token')
        ->and(array_map(fn (TeamIdentity $team): string => (string) $team->name, $store->teams()))->toBe(['Acme', 'Globex'])
        ->and(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0600');

    expect($store->use('acme-uuid'))->toBeTrue()
        ->and($store->token())->toBe('acme-token')
        ->and($store->use('nope'))->toBeFalse();

    $store->forget('acme-uuid');

    expect($store->token())->toBe('globex-token')
        ->and($store->teams())->toHaveCount(1);

    $store->forget('globex-uuid');

    expect($store->token())->toBeNull()
        ->and(is_file($this->path))->toBeFalse();
});

it('reads the single-team format written by earlier releases', function () {
    file_put_contents($this->path, json_encode(['token' => 'old-token', 'team' => ['uuid' => 'acme-uuid', 'name' => 'Acme']]));

    $store = new TokenStore($this->path);

    expect($store->token())->toBe('old-token')
        ->and($store->team()?->uuid)->toBe('acme-uuid')
        ->and($store->tokenFor('acme-uuid'))->toBe('old-token');
});

it('prefers the environment variable over the stored token', function () {
    $store = new TokenStore($this->path);
    $store->save('stored-token', new TeamIdentity('acme-uuid', 'Acme'));

    putenv(TokenStore::EnvVariable.'=env-token');

    expect($store->token())->toBe('env-token')
        ->and($store->tokenFor('other'))->toBe('env-token');
});

it('returns null when nothing is stored', function () {
    expect((new TokenStore($this->path))->token())->toBeNull()
        ->and((new TokenStore($this->path))->teams())->toBe([]);
});

it('resolves credentials from the project manifest team before the current team', function () {
    $store = new TokenStore($this->path);
    $store->save('acme-token', new TeamIdentity('acme-uuid', 'Acme'));
    $store->save('globex-token', new TeamIdentity('globex-uuid', 'Globex'));

    $manifest = new ProjectManifest;
    $project = $this->dir.'/project';
    mkdir($project);
    $manifest->write($project, 'project-uuid', 'acme-uuid');

    $resolver = new CredentialResolver($store, $manifest);

    expect($resolver->resolve($project)?->token)->toBe('acme-token')
        ->and($resolver->resolve($project)?->team?->name)->toBe('Acme')
        ->and($resolver->resolve($this->dir)?->token)->toBe('globex-token')
        ->and($resolver->resolve($project, 'globex-uuid')?->token)->toBe('globex-token')
        ->and($manifest->team($project))->toBe('acme-uuid');

    $store->forget('acme-uuid');

    expect($resolver->resolve($project))->toBeNull()
        ->and($resolver->missingTeam($project))->toBe('acme-uuid');

    putenv(TokenStore::EnvVariable.'=ci-token');

    expect($resolver->resolve($project)?->token)->toBe('ci-token');
});
