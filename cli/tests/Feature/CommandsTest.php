<?php

declare(strict_types=1);

use App\Services\LockContentHash;
use Illuminate\Support\Sleep;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\Diagnostics\EdgeProbe;
use Vaults\VaultsClient;

beforeEach(function () {
    Sleep::fake();
    $this->transport = new FakeTransport;
    $this->workDir = sys_get_temp_dir().'/vaults-cli-tests/'.uniqid();
    mkdir($this->workDir, 0755, true);

    $this->tokenStore = new TokenStore($this->workDir.'/config.json');
    $this->app->instance(TokenStore::class, $this->tokenStore);
    $this->app->instance(VaultsClient::class, new VaultsClient('test-token', 'https://vaults.test', $this->transport));

    $this->previousDir = getcwd();
    chdir($this->workDir);
});

afterEach(function () {
    chdir($this->previousDir);
});

it('logs in with a pasted token', function () {
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'team-uuid', 'name' => 'Acme']]]);

    $this->artisan('login', ['--token' => 'pasted-token'])
        ->expectsOutputToContain('Logged in to team: Acme')
        ->assertExitCode(0);

    expect($this->tokenStore->token())->toBe('pasted-token');
});

it('rejects an invalid pasted token', function () {
    $this->transport->queueJson(['message' => 'Unauthenticated.'], 401);

    $this->artisan('login', ['--token' => 'bad-token'])
        ->expectsOutputToContain('That token was rejected')
        ->assertExitCode(1);

    expect($this->tokenStore->token())->toBeNull();
});

it('logs out', function () {
    $this->tokenStore->save('stored');

    $this->artisan('logout')->assertExitCode(0);

    expect($this->tokenStore->token())->toBeNull();
});

it('checks deposit status with a ci-friendly exit code', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [
        'total' => 2,
        'deposited' => 1,
        'undeposited' => 1,
        'packages' => [
            ['name' => 'a/b', 'version' => 'v1.0.0', 'deposited' => true, 'security_status' => 'clear'],
            ['name' => 'c/d', 'version' => 'v2.0.0', 'deposited' => false, 'security_status' => null],
        ],
    ]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsOutputToContain('1/2 deposited, 1 undeposited.')
        ->assertExitCode(1);
});

it('reports success when everything is deposited', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [
        'total' => 1,
        'deposited' => 1,
        'undeposited' => 0,
        'packages' => [
            ['name' => 'a/b', 'version' => 'v1.0.0', 'deposited' => true, 'security_status' => 'clear'],
        ],
    ]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsOutputToContain('All packages are deposited.')
        ->assertExitCode(0);
});

it('runs a full deposit and writes the lock with --write', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 1]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[],"rewritten":true}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit', ['--write' => true])
        ->expectsConfirmation('Add it now?', 'no')
        ->expectsOutputToContain('composer.lock rewritten to install from Vaults')
        ->expectsOutputToContain('repositories')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"rewritten":true');
});

it('errors without a composer.lock', function () {
    $this->artisan('deposit', ['--check' => true])
        ->expectsOutputToContain('No composer.lock found')
        ->assertExitCode(1);
});

it('shows project status from the manifest', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [[
        'uuid' => 'project-uuid',
        'name' => 'Vaults App',
        'repository_published' => true,
        'deposit_percentage' => 98.5,
        'latest_run' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 240, 'packages_deposited' => 238],
    ]]]);

    $this->artisan('status')
        ->expectsOutputToContain('Vaults App')
        ->expectsOutputToContain('98.5%')
        ->assertExitCode(0);
});

it('fails status without a manifest when non-interactive', function () {
    $this->artisan('status', ['--no-interaction' => true])
        ->expectsOutputToContain('not linked to a Vaults project')
        ->assertExitCode(1);
});

it('links interactively by creating a project when none exist', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/composer.json', '{"name":"acme/my-app"}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'my-app']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsQuestion('What should the new project be called?', 'my-app')
        ->expectsOutputToContain('Linked this directory to "my-app"')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'new-uuid']);
});

it('normalises a typed project name before creating it', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'checkout-api']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsQuestion('What should the new project be called?', 'Checkout API')
        ->expectsOutputToContain('Linked this directory to "checkout-api"')
        ->assertExitCode(0);

    expect(json_decode((string) $this->transport->requests[1]->body, true))->toMatchArray(['name' => 'checkout-api']);
});

it('asks again when the dashboard rejects the project name', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['message' => 'A project with this name already exists in this team.', 'errors' => ['name' => ['A project with this name already exists in this team.']]], 422);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'checkout-api-2']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsQuestion('What should the new project be called?', 'checkout-api')
        ->expectsOutputToContain('already exists in this team')
        ->expectsQuestion('What should the new project be called?', 'checkout-api-2')
        ->expectsOutputToContain('Linked this directory to "checkout-api-2"')
        ->assertExitCode(0);
});

it('reports a device login that was denied in the browser', function () {
    $this->app->instance(VaultsClient::class, new VaultsClient(null, 'https://vaults.test', $this->transport));

    $this->transport->queueJson(['data' => [
        'device_code' => 'plain-code',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://vaults.test/device',
        'verification_uri_complete' => 'https://vaults.test/device?code=ABCD-EFGH',
        'expires_in' => 900,
        'interval' => 5,
    ]], 201);
    $this->transport->queueJson(['message' => 'Not Found.'], 404);

    $this->artisan('login', ['--no-interaction' => true])
        ->expectsOutputToContain('denied in the browser')
        ->assertExitCode(1);

    Sleep::assertSleptTimes(1);

    expect($this->tokenStore->token())->toBeNull();
});

it('links interactively by selecting an existing project', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => [['uuid' => 'existing-uuid', 'name' => 'Existing App']]]);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $this->artisan('deposit', ['--check' => true])
        ->expectsQuestion('Which Vaults project should this directory belong to?', 'existing-uuid')
        ->expectsOutputToContain('Linked this directory to "Existing App"')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'existing-uuid']);
});

it('persists the project link when --project is passed', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $this->artisan('deposit', ['--check' => true, '--project' => 'flag-uuid'])
        ->expectsOutputToContain('.vaults.json written')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'flag-uuid']);
});

it('fails deposit without a link when non-interactive', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->artisan('deposit', ['--check' => true, '--no-interaction' => true])
        ->expectsOutputToContain('not linked to a Vaults project')
        ->assertExitCode(1);
});

it('initialises a directory via vaults init', function () {
    file_put_contents($this->workDir.'/composer.json', '{"name":"acme/fresh-app"}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'fresh-uuid', 'name' => 'fresh-app']], 201);

    $this->artisan('init')
        ->expectsQuestion('What should the new project be called?', 'fresh-app')
        ->expectsOutputToContain('Next: vaults deposit --check')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'fresh-uuid']);
});

it('does nothing when init runs in an already linked directory', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"already-uuid"}');

    $this->artisan('init')
        ->expectsOutputToContain('already linked')
        ->assertExitCode(0);
});

it('offers to wire composer.json after an interactive deposit', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $writer = new class extends ComposerConfigWriter
    {
        public ?string $url = null;

        public function addRepository(string $directory, string $url): bool
        {
            $this->url = $url;

            return true;
        }
    };
    $this->app->instance(ComposerConfigWriter::class, $writer);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit')
        ->expectsConfirmation('Add it now?', 'yes')
        ->expectsOutputToContain('composer.json updated')
        ->assertExitCode(0);

    expect($writer->url)->toBe('https://repo.vaults-edge.net/repo/projects/abc');
});

it('reports a healthy doctor run', function () {
    file_put_contents($this->workDir.'/composer.lock', '{}');
    $this->tokenStore->save('stored-token');

    $probe = Mockery::mock(EdgeProbe::class);
    $probe->shouldReceive('resolve')->twice()->andReturn(['x.sni.global.fastly.net']);
    $probe->shouldReceive('healthCheck')->twice()->andReturn(true);
    $this->app->instance(EdgeProbe::class, $probe);

    $this->transport->queueJson(['data' => ['status' => 'ok']]);
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'u', 'name' => 'Acme']]]);

    $this->artisan('doctor')
        ->expectsOutputToContain('Everything looks healthy.')
        ->assertExitCode(0);
});

it('fails doctor when the edge is unhealthy', function () {
    $probe = Mockery::mock(EdgeProbe::class);
    $probe->shouldReceive('resolve')->twice()->andReturn([]);
    $probe->shouldReceive('healthCheck')->never();
    $this->app->instance(EdgeProbe::class, $probe);

    $this->transport->queueJson(['data' => ['status' => 'ok']]);

    $this->artisan('doctor')
        ->expectsOutputToContain('Some checks failed.')
        ->assertExitCode(1);
});

it('skips the wiring offer when the repository is already configured', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.json', json_encode([
        'name' => 'acme/my-app',
        'repositories' => [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
    ]));

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit')
        ->expectsOutputToContain('already configured in composer.json')
        ->assertExitCode(0);
});

it('waits for the real package total before showing progress', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 2, 'packages_deposited' => 1]]);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 2, 'packages_deposited' => 2]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit')
        ->expectsConfirmation('Add it now?', 'no')
        ->expectsOutputToContain('Deposited: 2')
        ->assertExitCode(0);
});

it('refreshes the lock content hash to match the wired composer.json', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.json', json_encode([
        'name' => 'acme/my-app',
        'repositories' => [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
    ]));

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"content-hash": "0000000000000000000000000000dead", "packages": []}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit', ['--write' => true])
        ->expectsOutputToContain('already configured in composer.json')
        ->assertExitCode(0);

    $expected = app(LockContentHash::class)->contentHash((string) file_get_contents($this->workDir.'/composer.json'));

    expect((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"content-hash": "'.$expected.'"')
        ->not->toContain('dead');
});

function queueCreatedKey($transport, string $token, ?string $expiresAt = '2027-09-08T00:00:00Z', array $existing = []): void
{
    $transport->queueJson([
        'data' => ['uuid' => 'key-new', 'name' => 'tom-macbook', 'project' => null, 'packages' => null, 'expires_at' => $expiresAt],
        'token' => $token,
        'host' => 'private.vaults-edge.net',
        'repository_url' => 'https://private.vaults-edge.net',
    ], 201);
    $transport->queueJson(['data' => $existing]);
}

it('links a project for private hosting by creating a named key and writing it to auth.json', function () {
    file_put_contents($this->workDir.'/composer.json', json_encode([
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://private.vaults-edge.net', 'canonical' => false],
        ],
    ], JSON_PRETTY_PRINT));

    queueCreatedKey($this->transport, 'vault-key-xyz', existing: [
        ['uuid' => 'key-old', 'name' => 'tom-macbook', 'project' => null, 'packages' => null, 'expires_at' => null],
        ['uuid' => 'key-ci', 'name' => 'GitHub Actions', 'project' => null, 'packages' => null, 'expires_at' => null],
    ]);
    $this->transport->queueJson([], 204);

    $this->artisan('private:link', ['--name' => 'tom-macbook', '--expires' => '90', '--no-public' => true])
        ->expectsOutputToContain('already configured in composer.json')
        ->expectsOutputToContain('Created private access key "tom-macbook"')
        ->expectsOutputToContain('rotates it')
        ->assertExitCode(0)
        ->run();

    $auth = json_decode((string) file_get_contents($this->workDir.'/auth.json'), true);
    $requests = $this->transport->requests;

    expect($auth['bearer']['private.vaults-edge.net'])->toBe('vault-key-xyz')
        ->and($requests[0]->method)->toBe('POST')
        ->and(json_decode((string) $requests[0]->body, true))->toBe(['name' => 'tom-macbook', 'expires_in_days' => 90])
        ->and($requests[1]->method)->toBe('GET')
        ->and($requests[2]->method)->toBe('DELETE')
        ->and($requests[2]->url)->toEndWith('/private-keys/key-old')
        ->and($requests)->toHaveCount(3);
});

function publishedProject(bool $published): array
{
    return ['data' => [[
        'uuid' => 'project-uuid',
        'name' => 'consumer',
        'repository' => $published ? ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/project-uuid'] : [],
        'repository_published' => $published,
        'deposit_percentage' => $published ? 100 : 0,
    ]]];
}

it('also wires the public project repository from private:link when asked', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $writer = new class extends ComposerConfigWriter
    {
        public ?string $publicUrl = null;

        public ?string $privateUrl = null;

        public function addRepository(string $directory, string $url): bool
        {
            $this->publicUrl = $url;

            return true;
        }

        public function addPrivateRepository(string $directory, string $url): bool
        {
            $this->privateUrl = $url;

            return true;
        }
    };
    $this->app->instance(ComposerConfigWriter::class, $writer);

    queueCreatedKey($this->transport, 'vault-key-xyz');
    $this->transport->queueJson(publishedProject(true));

    $this->artisan('private:link', ['--with-public' => true])
        ->expectsOutputToContain('Added the public Vaults repository to composer.json')
        ->assertExitCode(0)
        ->run();

    expect($writer->privateUrl)->toBe('https://private.vaults-edge.net')
        ->and($writer->publicUrl)->toBe('https://repo.vaults-edge.net/repo/projects/project-uuid');
});

it('deposits automatically when the public repository is not published yet', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.json', '{"repositories":[{"type":"composer","url":"https://private.vaults-edge.net","canonical":false}]}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    queueCreatedKey($this->transport, 'vault-key-xyz');
    $this->transport->queueJson(publishedProject(false));
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 0, 'packages_deposited' => 0]], 202);
    $this->transport->queueJson(['composer_lock' => '{"packages":[]}', 'repositories' => ['project' => [], 'global' => []]]);

    $this->artisan('private:link', ['--with-public' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Depositing this project so its repository exists')
        ->assertExitCode(0);
});

it('skips the public repository entirely with --no-public', function () {
    file_put_contents($this->workDir.'/composer.json', '{"repositories":[{"type":"composer","url":"https://private.vaults-edge.net","canonical":false}]}');
    queueCreatedKey($this->transport, 'vault-key-xyz');

    $this->artisan('private:link', ['--no-public' => true])->assertExitCode(0);

    expect($this->transport->requests)->toHaveCount(2);
});

it('fails private:link when not authenticated', function () {
    $this->transport->queueJson(['message' => 'Unauthenticated.'], 401);

    $this->artisan('private:link')
        ->expectsOutputToContain('Not authenticated')
        ->assertExitCode(1);
});

it('writes the private key to the global composer auth.json with --global', function () {
    $composerHome = $this->workDir.'/composer-home';
    putenv('COMPOSER_HOME='.$composerHome);

    file_put_contents($this->workDir.'/composer.json', json_encode([
        'repositories' => [
            ['type' => 'composer', 'url' => 'https://private.vaults-edge.net', 'canonical' => false],
        ],
    ], JSON_PRETTY_PRINT));

    queueCreatedKey($this->transport, 'global-key', null);

    $this->artisan('private:link', ['--global' => true, '--no-public' => true])->assertExitCode(0);

    $auth = json_decode((string) file_get_contents($composerHome.'/auth.json'), true);

    expect($auth['bearer']['private.vaults-edge.net'])->toBe('global-key');

    putenv('COMPOSER_HOME');
});

it('rejects an out-of-range expiry on private:link before calling the api', function () {
    $this->artisan('private:link', ['--expires' => '0'])->assertExitCode(1);

    expect($this->transport->requests)->toBe([]);
});

it('stores a team per login, lists them, and logs out of one at a time', function () {
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'acme-uuid', 'name' => 'Acme']]]);
    $this->artisan('login', ['--token' => 'acme-token'])->assertExitCode(0);

    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'globex-uuid', 'name' => 'Globex']]]);
    $this->artisan('login', ['--token' => 'globex-token'])
        ->expectsOutputToContain('This machine now holds 2 teams')
        ->assertExitCode(0);

    file_put_contents($this->workDir.'/.vaults.json', json_encode(['project' => 'p', 'team' => 'acme-uuid']));

    $this->artisan('teams')
        ->expectsOutputToContain('yes (.vaults.json)')
        ->expectsOutputToContain('Globex')
        ->assertExitCode(0);

    $this->artisan('teams', ['--use' => 'Acme'])
        ->expectsOutputToContain('Acme is now the default team.')
        ->assertExitCode(0);

    expect($this->tokenStore->token())->toBe('acme-token');

    $this->artisan('logout', ['--team' => 'Globex'])
        ->expectsOutputToContain('Logged out of Globex')
        ->assertExitCode(0);

    expect($this->tokenStore->teams())->toHaveCount(1);

    $this->artisan('logout', ['--all' => true])->assertExitCode(0);

    expect($this->tokenStore->token())->toBeNull();
});

it('records the team in the manifest when linking a project', function () {
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'acme-uuid', 'name' => 'Acme']]]);
    $this->artisan('login', ['--token' => 'acme-token'])->assertExitCode(0);

    $this->artisan('init', ['--project' => 'project-uuid'])->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'project-uuid', 'team' => 'acme-uuid']);
});

it('opens the dashboard for the connect command', function () {
    $this->artisan('connect')
        ->expectsOutputToContain('Connections')
        ->assertExitCode(0);
});

it('reports private packages separately in the deposit summary', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 3]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 3, 'packages_deposited' => 2, 'packages_skipped' => 1, 'packages_private' => 1]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('deposit')
        ->expectsConfirmation('Add it now?', 'no')
        ->expectsOutputToContain('Private (served from your team repository): 1')
        ->expectsOutputToContain('Coverage: 100% of 2 coverable packages.')
        ->assertExitCode(0);
});

it('lists private access keys', function () {
    $this->transport->queueJson(['data' => [
        ['uuid' => 'key-1', 'name' => 'CI', 'project' => ['uuid' => 'p', 'name' => 'Shop'], 'packages' => ['acme/lib'], 'expires_at' => '2027-03-01T00:00:00Z'],
    ]]);

    $this->artisan('private:keys')
        ->expectsTable(['Key', 'Name', 'Scope', 'Expires'], [['key-1', 'CI', 'Shop · acme/lib', '2027-03-01']])
        ->assertExitCode(0);
});

it('creates a private access key and prints the auth.json snippet once', function () {
    $this->transport->queueJson(['data' => ['uuid' => 'key-2', 'name' => 'GitHub Actions', 'project' => null, 'packages' => null, 'expires_at' => '2027-09-07T00:00:00Z'], 'token' => 'signed.key', 'host' => 'private.vaults-edge.net'], 201);

    $this->artisan('private:keys:create', ['name' => 'GitHub Actions', '--expires' => '365', '--package' => ['acme/lib']])
        ->expectsOutputToContain('Created private access key "GitHub Actions" (key-2)')
        ->expectsOutputToContain('{"bearer":{"private.vaults-edge.net":"signed.key"}}')
        ->expectsOutputToContain('vaults private:keys:revoke key-2')
        ->assertExitCode(0)
        ->run();

    $request = $this->transport->lastRequest();

    expect($request->method)->toBe('POST')
        ->and($request->url)->toBe('https://vaults.test/api/v1/private-keys')
        ->and(json_decode((string) $request->body, true))->toBe(['name' => 'GitHub Actions', 'expires_in_days' => 365, 'packages' => ['acme/lib']]);
});

it('writes a created key straight into auth.json with --write', function () {
    $this->transport->queueJson(['data' => ['uuid' => 'key-3', 'name' => 'deploy', 'project' => null, 'packages' => null, 'expires_at' => null], 'token' => 'signed.key', 'host' => 'private.vaults-edge.net'], 201);

    $this->artisan('private:keys:create', ['name' => 'deploy', '--write' => true])
        ->expectsOutputToContain('Wrote the key to')
        ->doesntExpectOutputToContain('signed.key')
        ->assertExitCode(0)
        ->run();

    $auth = json_decode((string) file_get_contents($this->workDir.'/auth.json'), true);

    expect($auth['bearer']['private.vaults-edge.net'])->toBe('signed.key');
});

it('rejects an out-of-range expiry before calling the api', function () {
    $this->artisan('private:keys:create', ['name' => 'x', '--expires' => '9999'])
        ->expectsOutputToContain('--expires must be between 1 and 730 days.')
        ->assertExitCode(1)
        ->run();

    expect($this->transport->requests)->toBe([]);
});

it('revokes a private access key', function () {
    $this->transport->queueJson([], 204);

    $this->artisan('private:keys:revoke', ['key' => 'key-1'])
        ->expectsOutputToContain('Key revoked.')
        ->assertExitCode(0)
        ->run();

    expect($this->transport->lastRequest()->url)->toBe('https://vaults.test/api/v1/private-keys/key-1')
        ->and($this->transport->lastRequest()->method)->toBe('DELETE');
});
