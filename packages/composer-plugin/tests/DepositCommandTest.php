<?php

declare(strict_types=1);

use Composer\Console\Application;
use Composer\Package\Locker;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeIO;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\ComposerPlugin\DepositCommand;
use Vaults\ComposerPlugin\VaultsPlugin;
use Vaults\Support\FakeSleeper;
use Vaults\VaultsClient;

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->workDir = sys_get_temp_dir().'/vaults-plugin-tests/'.uniqid();
    mkdir($this->workDir, 0755, true);

    $this->store = new TokenStore($this->workDir.'/config.json');
    $this->store->save('test-token');
    $this->io = new FakeIO;

    $command = new DepositCommand(
        new VaultsClient(null, 'https://vaults.test', $this->transport, 'https://auth.vaults.test'),
        $this->store,
        $this->workDir,
        new FakeSleeper,
        $this->io,
    );
    $command->setApplication(new Application);

    $this->tester = new CommandTester($command);
});

it('exposes the deposit command through the plugin capability', function () {
    $plugin = new VaultsPlugin;
    $provider = new (array_values($plugin->getCapabilities())[0]);

    expect($provider->getCommands()[0])->toBeInstanceOf(DepositCommand::class);
});

it('checks deposit with a ci-friendly exit code', function () {
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

    $exit = $this->tester->execute(['--check' => true]);

    expect($exit)->toBe(1)
        ->and($this->tester->getDisplay())->toContain('1/2 deposited, 1 undeposited.');

    $authorization = $this->transport->requests[0]->headers['Authorization'] ?? null;

    expect($authorization)->toBe('Bearer test-token');
});

it('runs a full deposit and writes the lock', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 1]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[],"rewritten":true}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        ],
    ]);

    $exit = $this->tester->execute(['--write' => true]);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('composer.lock rewritten to install from Vaults')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"rewritten":true');
});

it('fails without authentication when non-interactive', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    $this->store->clear();

    $exit = $this->tester->execute([], ['interactive' => false]);

    expect($exit)->toBe(1)
        ->and($this->tester->getDisplay())->toContain('Not authenticated.')
        ->and($this->tester->getDisplay())->toContain('VAULTS_TOKEN');
});

it('logs in via the device flow when interactive and unauthenticated', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    $this->store->clear();

    $this->transport->queueJson(['data' => [
        'device_code' => 'plain-code',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://vaults.test/device',
        'verification_uri_complete' => 'https://vaults.test/device?code=ABCD-EFGH',
        'expires_in' => 900,
        'interval' => 5,
    ]], 201);
    $this->transport->queueJson(['data' => ['status' => 'approved', 'token' => 'issued-token', 'team' => ['uuid' => 'u', 'name' => 'Acme']]]);
    $this->transport->queueJson(['data' => [
        'total' => 1,
        'deposited' => 1,
        'undeposited' => 0,
        'packages' => [['name' => 'a/b', 'version' => 'v1.0.0', 'deposited' => true, 'security_status' => 'clear']],
    ]]);

    $exit = $this->tester->execute(['--check' => true], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('ABCD-EFGH')
        ->and($this->tester->getDisplay())->toContain('Logged in to team: Acme')
        ->and($this->tester->getDisplay())->toContain('All packages are deposited.')
        ->and($this->store->token())->toBe('issued-token');
});

it('persists the project link when --project is passed', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => [
        'total' => 0,
        'deposited' => 0,
        'undeposited' => 0,
        'packages' => [],
    ]]);

    $exit = $this->tester->execute(['--check' => true, '--project' => 'project-uuid']);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('.vaults.json written')
        ->and(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'project-uuid']);
});

it('fails without a project link when non-interactive', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $exit = $this->tester->execute([], ['interactive' => false]);

    expect($exit)->toBe(1)
        ->and($this->tester->getDisplay())->toContain('not linked to a Vaults project');
});

it('links interactively by creating a project when none exist', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/composer.json', '{"name":"acme/my-app"}');

    $this->io->queue('my-app');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'my-app']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $exit = $this->tester->execute(['--check' => true], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('Linked this directory to "my-app"')
        ->and(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'new-uuid'])
        ->and($this->io->questions[0])->toContain('What should the new project be called?');
});

it('normalises a typed project name and retries when the dashboard rejects it', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->io->queue('Checkout API');
    $this->io->queue('checkout-api-2');

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['message' => 'A project with this name already exists in this team.', 'errors' => ['name' => ['A project with this name already exists in this team.']]], 422);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'checkout-api-2']], 201);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $exit = $this->tester->execute(['--check' => true], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and(json_decode((string) $this->transport->requests[1]->body, true))->toMatchArray(['name' => 'checkout-api'])
        ->and(json_decode((string) $this->transport->requests[2]->body, true))->toMatchArray(['name' => 'checkout-api-2'])
        ->and($this->tester->getDisplay())->toContain('already exists in this team')
        ->and($this->tester->getDisplay())->toContain('Linked this directory to "checkout-api-2"');
});

it('reports a device login that was denied in the browser', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    $this->store->clear();

    $this->transport->queueJson(['data' => [
        'device_code' => 'plain-code',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://vaults.test/device',
        'verification_uri_complete' => 'https://vaults.test/device?code=ABCD-EFGH',
        'expires_in' => 900,
        'interval' => 5,
    ]], 201);
    $this->transport->queueJson(['message' => 'Not Found.'], 404);

    $exit = $this->tester->execute(['--check' => true], ['interactive' => true]);

    expect($exit)->not->toBe(0)
        ->and($this->tester->getDisplay())->toContain('denied in the browser')
        ->and($this->store->token())->toBeNull();
});

it('links interactively by selecting an existing project', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->io->queue(1);

    $this->transport->queueJson(['data' => [['uuid' => 'existing-uuid', 'name' => 'Existing App']]]);
    $this->transport->queueJson(['data' => ['total' => 0, 'deposited' => 0, 'undeposited' => 0, 'packages' => []]]);

    $exit = $this->tester->execute(['--check' => true], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('Linked this directory to "Existing App"')
        ->and(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true))->toBe(['project' => 'existing-uuid']);
});

it('wires the repository into composer.json after an interactive deposit', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/composer.json', "{\n    \"name\": \"acme/my-app\"\n}\n");
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->io->queue(true);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        ],
    ]);

    $exit = $this->tester->execute([], ['interactive' => true]);

    $composerJson = json_decode((string) file_get_contents($this->workDir.'/composer.json'), true);

    $repositoryUrls = array_column($composerJson['repositories'] ?? [], 'url');

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('composer.json updated')
        ->and($composerJson['name'])->toBe('acme/my-app')
        ->and($repositoryUrls)->toContain('https://repo.vaults-edge.net/repo/projects/abc');
});

it('fails without a composer.lock', function () {
    $exit = $this->tester->execute([]);

    expect($exit)->toBe(1)
        ->and($this->tester->getDisplay())->toContain('No composer.lock found');
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
        ],
    ]);

    $exit = $this->tester->execute([], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('already configured in composer.json')
        ->and($this->io->questions)->toBeEmpty();
});

it('refreshes the lock content hash to match the wired composer.json', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.json', "{\n    \"name\": \"acme/my-app\"\n}\n");

    $this->io->queue(true);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]], 202);
    $this->transport->queueJson([
        'composer_lock' => '{"content-hash": "0000000000000000000000000000dead", "packages": []}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        ],
    ]);

    $exit = $this->tester->execute(['--write' => true], ['interactive' => true]);

    $expected = Locker::getContentHash((string) file_get_contents($this->workDir.'/composer.json'));

    expect($exit)->toBe(0)
        ->and($this->tester->getDisplay())->toContain('composer.json updated')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"content-hash": "'.$expected.'"')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->not->toContain('dead');
});

it('lists every package that did not deposit with its reason and offers credentials from auth.json', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/auth.json', json_encode(['http-basic' => ['satis.dedoc.co' => ['username' => 'tom', 'password' => 'hunter2']]]));

    $failedRun = ['uuid' => 'run-1', 'status' => 'completed', 'packages_total' => 4, 'packages_deposited' => 2, 'packages_skipped' => 1, 'packages_failed' => 1, 'items' => [
        ['uuid' => 'i1', 'status' => 'deposited', 'error' => null, 'package' => 'vendor/lib', 'version' => 'v1.0.0', 'reference' => 'a', 'security_status' => 'clear'],
        ['uuid' => 'i2', 'status' => 'failed', 'error' => 'credentials required for satis.dedoc.co; add them under Team settings → Repositories', 'reason' => 'credentials_required', 'host' => 'satis.dedoc.co', 'package' => 'dedoc/scramble-pro', 'version' => 'v0.9.15', 'reference' => 'b', 'security_status' => 'unknown'],
        ['uuid' => 'i3', 'status' => 'skipped', 'error' => 'local dependency (path repository) — nothing to download', 'reason' => 'path_repository', 'package' => 'acme/local', 'version' => 'dev-main', 'reference' => 'c', 'security_status' => 'unknown'],
    ]];

    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'pending', 'packages_total' => 4]], 202);
    $this->transport->queueJson(['data' => $failedRun]);
    $this->transport->queueJson(['data' => ['uuid' => 'c1', 'host' => 'satis.dedoc.co', 'type' => 'http-basic', 'username' => 'tom']], 201);
    $this->transport->queueJson(['data' => ['uuid' => 'run-2', 'status' => 'pending', 'packages_total' => 4]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-2', 'status' => 'completed', 'packages_total' => 4, 'packages_deposited' => 3, 'packages_skipped' => 1, 'packages_failed' => 0, 'items' => [
        ['uuid' => 'i2', 'status' => 'deposited', 'error' => null, 'private' => true, 'package' => 'dedoc/scramble-pro', 'version' => 'v0.9.15', 'reference' => 'b', 'security_status' => 'unknown'],
    ]]]);
    $this->transport->queueJson([
        'composer_lock' => '{"packages":[]}',
        'repositories' => [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
            'private' => ['type' => 'composer', 'url' => 'https://private.vaults-edge.net', 'canonical' => false],
        ],
    ]);

    $this->io->queue(true)->queue(false);

    $exit = $this->tester->execute([]);
    $display = $this->tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain('Not deposited')
        ->and($display)->toContain('dedoc/scramble-pro v0.9.15  needs credentials for satis.dedoc.co')
        ->and($display)->toContain('composer vaults:repositories:add satis.dedoc.co')
        ->and($display)->toContain('1 local path dependency skipped')
        ->and($display)->toContain('Saved credentials for satis.dedoc.co')
        ->and($display)->toContain('dedoc/scramble-pro was deposited as a private package for your team.')
        ->and($display)->toContain('composer vaults:private:link')
        ->and($this->io->questions[0])->toContain('Give Vaults the credentials for satis.dedoc.co from ./auth.json and deposit again?')
        ->and(json_decode((string) $this->transport->requests[2]->body, true))->toBe(['host' => 'satis.dedoc.co', 'type' => 'http-basic', 'secret' => 'hunter2', 'username' => 'tom']);
});

it('exits non-zero when a package failed to deposit even though the run completed', function () {
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'pending', 'packages_total' => 2]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'completed', 'packages_total' => 2, 'packages_deposited' => 1, 'packages_failed' => 1, 'items' => [
        ['uuid' => 'i2', 'status' => 'failed', 'error' => 'private repository; connect it under Sources to host it', 'reason' => 'private_repository', 'package' => 'acme/secret', 'version' => 'v2.0.0', 'reference' => 'b', 'security_status' => 'unknown'],
    ]]]);
    $this->transport->queueJson(['composer_lock' => '{"packages":[]}', 'repositories' => ['project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']]]);

    $exit = $this->tester->execute([], ['interactive' => false]);
    $display = $this->tester->getDisplay();

    expect($exit)->toBe(1)
        ->and($display)->toContain('acme/secret v2.0.0  private repository, not hosted on Vaults yet')
        ->and($display)->toContain('Team settings › Sources')
        ->and($display)->toContain('1 package did not deposit; installs still depend on their original hosts.');
});

it('detects paid repositories in composer.lock before depositing and asks to use the local credentials', function () {
    file_put_contents($this->workDir.'/composer.lock', json_encode(['packages' => [
        ['name' => 'dedoc/scramble-pro', 'version' => 'v0.9.15', 'dist' => ['url' => 'https://satis.dedoc.co/dist/dedoc/scramble-pro/0.9.15.zip']],
    ]]));
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/auth.json', json_encode(['bearer' => ['satis.dedoc.co' => 'tok']]));

    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'c1', 'host' => 'satis.dedoc.co', 'type' => 'bearer', 'username' => null]], 201);
    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'pending', 'packages_total' => 1]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1, 'items' => [
        ['uuid' => 'i2', 'status' => 'deposited', 'error' => null, 'private' => true, 'package' => 'dedoc/scramble-pro', 'version' => 'v0.9.15', 'reference' => 'b', 'security_status' => 'unknown'],
    ]]]);
    $this->transport->queueJson(['composer_lock' => '{"packages":[]}', 'repositories' => [
        'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        'private' => ['type' => 'composer', 'url' => 'https://private.vaults-edge.net'],
    ]]);

    $this->io->queue(true)->queue(false);

    $exit = $this->tester->execute([]);
    $display = $this->tester->getDisplay();

    expect($exit)->toBe(0)
        ->and($display)->toContain('Private repositories in composer.lock')
        ->and($display)->toContain('satis.dedoc.co  1 package  dedoc/scramble-pro')
        ->and($display)->toContain('credentials in ./auth.json (bearer)')
        ->and($this->io->questions[0])->toBe('Use the satis.dedoc.co credentials? [Y/n] ')
        ->and($display)->toContain('Saved credentials for satis.dedoc.co')
        ->and($display)->toContain('composer vaults:private:link')
        ->and($this->transport->requests[0]->url)->toBe('https://vaults.test/api/v1/repository-credentials')
        ->and(json_decode((string) $this->transport->requests[1]->body, true))->toBe(['host' => 'satis.dedoc.co', 'type' => 'bearer', 'secret' => 'tok']);
});

it('skips the up-front question when the team already holds the credentials', function () {
    file_put_contents($this->workDir.'/composer.lock', json_encode(['packages' => [
        ['name' => 'dedoc/scramble-pro', 'version' => 'v0.9.15', 'dist' => ['url' => 'https://satis.dedoc.co/dist/dedoc/scramble-pro/0.9.15.zip']],
    ]]));
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/auth.json', json_encode(['bearer' => ['satis.dedoc.co' => 'tok']]));

    $this->transport->queueJson(['data' => [['uuid' => 'c1', 'host' => 'satis.dedoc.co', 'type' => 'bearer', 'username' => null]]]);
    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'pending', 'packages_total' => 1]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-1', 'status' => 'completed', 'packages_total' => 1, 'packages_deposited' => 1]]);
    $this->transport->queueJson(['composer_lock' => '{"packages":[]}', 'repositories' => ['project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']]]);

    $this->io->queue(false);

    expect($this->tester->execute([]))->toBe(0)
        ->and($this->io->questions)->toBe(['Add it now? [Y/n] '])
        ->and(count($this->transport->requests))->toBe(4);
});
