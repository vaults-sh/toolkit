<?php

declare(strict_types=1);

use App\Services\ComposerConfigWriter;
use App\Services\EdgeProbe;
use App\Services\LockContentHash;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\VaultsClient;

beforeEach(function () {
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
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'team-uuid', 'name' => 'Cranbri']]]);

    $this->artisan('login', ['--token' => 'pasted-token'])
        ->expectsOutputToContain('Logged in to team: Cranbri')
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

it('checks protection status with a ci-friendly exit code', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [
        'total' => 2,
        'protected' => 1,
        'unprotected' => 1,
        'packages' => [
            ['name' => 'a/b', 'version' => 'v1.0.0', 'protected' => true, 'security_status' => 'clear'],
            ['name' => 'c/d', 'version' => 'v2.0.0', 'protected' => false, 'security_status' => null],
        ],
    ]]);

    $this->artisan('protect', ['--check' => true])
        ->expectsOutputToContain('1/2 protected, 1 unprotected.')
        ->assertExitCode(1);
});

it('reports success when everything is protected', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [
        'total' => 1,
        'protected' => 1,
        'unprotected' => 0,
        'packages' => [
            ['name' => 'a/b', 'version' => 'v1.0.0', 'protected' => true, 'security_status' => 'clear'],
        ],
    ]]);

    $this->artisan('protect', ['--check' => true])
        ->expectsOutputToContain('All packages are protected.')
        ->assertExitCode(0);
});

it('runs a full protection and writes the lock with --write', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 1]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_protected' => 1]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[],"rewritten":true}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('protect', ['--write' => true])
        ->expectsConfirmation('Add it now?', 'no')
        ->expectsOutputToContain('composer.lock rewritten to install from Vaults')
        ->expectsOutputToContain('repositories')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"rewritten":true');
});

it('errors without a composer.lock', function () {
    $this->artisan('protect', ['--check' => true])
        ->expectsOutputToContain('No composer.lock found')
        ->assertExitCode(1);
});

it('shows project status from the manifest', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => [[
        'uuid' => 'project-uuid',
        'name' => 'Vaults App',
        'repository_published' => true,
        'protection_percentage' => 98.5,
        'latest_run' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 240, 'packages_protected' => 238],
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
    file_put_contents($this->workDir.'/composer.json', '{"name":"cranbri/my-app"}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'my-app']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'protected' => 0, 'unprotected' => 0, 'packages' => []]]);

    $this->artisan('protect', ['--check' => true])
        ->expectsQuestion('What should the new project be called?', 'my-app')
        ->expectsOutputToContain('Linked this directory to "my-app"')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'new-uuid']);
});

it('links interactively by selecting an existing project', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => [['uuid' => 'existing-uuid', 'name' => 'Existing App']]]);
    $this->transport->queueJson(['data' => ['total' => 0, 'protected' => 0, 'unprotected' => 0, 'packages' => []]]);

    $this->artisan('protect', ['--check' => true])
        ->expectsQuestion('Which Vaults project should this directory belong to?', 'existing-uuid')
        ->expectsOutputToContain('Linked this directory to "Existing App"')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'existing-uuid']);
});

it('persists the project link when --project is passed', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => ['total' => 0, 'protected' => 0, 'unprotected' => 0, 'packages' => []]]);

    $this->artisan('protect', ['--check' => true, '--project' => 'flag-uuid'])
        ->expectsOutputToContain('.vaults.json written')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'flag-uuid']);
});

it('fails protect without a link when non-interactive', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->artisan('protect', ['--check' => true, '--no-interaction' => true])
        ->expectsOutputToContain('not linked to a Vaults project')
        ->assertExitCode(1);
});

it('initialises a directory via vaults init', function () {
    file_put_contents($this->workDir.'/composer.json', '{"name":"cranbri/fresh-app"}');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'fresh-uuid', 'name' => 'fresh-app']], 201);

    $this->artisan('init')
        ->expectsQuestion('What should the new project be called?', 'fresh-app')
        ->expectsOutputToContain('Next: vaults protect --check')
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'fresh-uuid']);
});

it('does nothing when init runs in an already linked directory', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"already-uuid"}');

    $this->artisan('init')
        ->expectsOutputToContain('already linked')
        ->assertExitCode(0);
});

it('offers to wire composer.json after an interactive protect', function () {
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

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_protected' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('protect')
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
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'u', 'name' => 'Cranbri']]]);

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
        'name' => 'cranbri/my-app',
        'repositories' => [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
    ]));

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_protected' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('protect')
        ->expectsOutputToContain('already configured in composer.json')
        ->assertExitCode(0);
});

it('waits for the real package total before showing progress', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 2, 'packages_protected' => 1]]);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 2, 'packages_protected' => 2]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('protect')
        ->expectsConfirmation('Add it now?', 'no')
        ->expectsOutputToContain('Protected: 2')
        ->assertExitCode(0);
});

it('refreshes the lock content hash to match the wired composer.json', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.json', json_encode([
        'name' => 'cranbri/my-app',
        'repositories' => [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
    ]));

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_protected' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"content-hash": "0000000000000000000000000000dead", "packages": []}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'global' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/global'],
        ],
    ]);

    $this->artisan('protect', ['--write' => true])
        ->expectsOutputToContain('already configured in composer.json')
        ->assertExitCode(0);

    $expected = app(LockContentHash::class)->contentHash((string) file_get_contents($this->workDir.'/composer.json'));

    expect((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"content-hash": "'.$expected.'"')
        ->not->toContain('dead');
});
