<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Tests\Support\FakeIO;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\Composer\LockContentHash;
use Vaults\ComposerPlugin\VaultsPlugin;
use Vaults\Support\FakeSleeper;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

function testablePlugin(FakeTransport $transport, TokenStore $store, string $dir, ?FakeSleeper $sleeper = null): VaultsPlugin
{
    return new class($transport, $store, $dir, $sleeper ?? new FakeSleeper) extends VaultsPlugin
    {
        public function __construct(private FakeTransport $t, private TokenStore $s, private string $d, private FakeSleeper $sleeper) {}

        protected function sleeper(): Sleeper
        {
            return $this->sleeper;
        }

        protected function workingDirectory(): string
        {
            return $this->d;
        }

        protected function tokenStore(): TokenStore
        {
            return $this->s;
        }

        protected function client(): VaultsClient
        {
            return new VaultsClient(null, 'https://vaults.test', $this->t, 'https://auth.vaults.test');
        }
    };
}

function updateEvent(array $extra = [], ?FakeIO $io = null): Event
{
    $rootPackage = new RootPackage('vaults-test/root', '1.0.0.0', '1.0.0');
    $rootPackage->setExtra($extra);

    $composer = new Composer;
    $composer->setPackage($rootPackage);

    return new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io ?? new FakeIO);
}

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->workDir = sys_get_temp_dir().'/vaults-autodeposit-tests/'.uniqid();
    mkdir($this->workDir, 0755, true);
    $this->store = new TokenStore($this->workDir.'/config.json');
});

it('subscribes to the post-update event', function () {
    expect(VaultsPlugin::getSubscribedEvents())->toHaveKey(ScriptEvents::POST_UPDATE_CMD);
});

it('fires a deposit run after update when linked and authenticated', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 7]], 202);

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());

    $request = $this->transport->requests[0] ?? null;

    expect($request?->url)->toBe('https://vaults.test/api/v1/projects/project-uuid/deposit')
        ->and($request?->headers['Authorization'])->toBe('Bearer test-token');
});

it('does nothing when not authenticated', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());

    expect($this->transport->requests)->toBeEmpty();
});

it('does nothing when not linked to a project', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());

    expect($this->transport->requests)->toBeEmpty();
});

it('respects the auto-deposit opt-out', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    testablePlugin($this->transport, $this->store, $this->workDir)
        ->onPostUpdate(updateEvent(['vaults' => ['auto-deposit' => false]]));

    expect($this->transport->requests)->toBeEmpty();
});

it('never throws even when the api fails', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['message' => 'boom'], 500);

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());
})->throwsNoExceptions();

it('announces the background deposit without guessing a package count', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);

    $io = new FakeIO;
    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent(io: $io));

    $written = implode("\n", $io->written);

    expect($written)->toContain('depositing composer.lock in the background')
        ->and($written)->toContain('composer vaults:deposit --write')
        ->and($written)->not->toContain('0 packages');
});

function linkedProject(string $dir, TokenStore $store, array $repositories = [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']]): void
{
    $store->save('test-token');
    file_put_contents($dir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($dir.'/composer.lock', '{"packages":[]}');
    file_put_contents($dir.'/composer.json', json_encode(['name' => 'acme/my-app', 'repositories' => $repositories]));
}

function rewrittenLockResponse(array $repositories = []): array
{
    return [
        'composer_lock' => '{"content-hash": "0000000000000000000000000000dead", "packages": [], "pinned": true}',
        'repositories' => $repositories + [
            'project' => ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
        ],
    ];
}

function runAutoDeposit(FakeTransport $transport, TokenStore $store, string $dir, array $extra = [], ?FakeSleeper $sleeper = null): string
{
    $io = new FakeIO;

    testablePlugin($transport, $store, $dir, $sleeper)->onPostUpdate(updateEvent($extra, $io));

    return (string) preg_replace('/<\/?[a-z=,;]*>/', '', implode("\n", $io->written));
}

it('pins composer.lock when the deposit finishes within the wait', function () {
    linkedProject($this->workDir, $this->store);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'running', 'packages_total' => 143, 'packages_deposited' => 141, 'packages_already_deposited' => 141]]);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 143, 'packages_deposited' => 143, 'packages_already_deposited' => 141]]);
    $this->transport->queueJson(rewrittenLockResponse());

    $sleeper = new FakeSleeper;
    $written = runAutoDeposit($this->transport, $this->store, $this->workDir, sleeper: $sleeper);
    $lock = (string) file_get_contents($this->workDir.'/composer.lock');

    expect($written)->toContain('Vaults: 143 packages in composer.lock · 141 already in Vaults · 2 to deposit')
        ->and($written)->toContain('Vaults: ✓ composer.lock now installs from Vaults.')
        ->and($written)->not->toContain('in the background')
        ->and($lock)->toContain('"pinned": true')
        ->and($lock)->toContain('"content-hash": "'.(new LockContentHash)->contentHash((string) file_get_contents($this->workDir.'/composer.json')).'"')
        ->and($sleeper->sleeps)->toBe([2, 2]);
});

it('leaves composer.lock alone when the deposit is still running after the wait', function () {
    linkedProject($this->workDir, $this->store);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);

    foreach (range(1, 15) as $poll) {
        $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'running', 'packages_total' => 500, 'packages_deposited' => $poll, 'packages_already_deposited' => 0]]);
    }

    $sleeper = new FakeSleeper;
    $written = runAutoDeposit($this->transport, $this->store, $this->workDir, sleeper: $sleeper);

    expect($written)->toContain('depositing composer.lock in the background')
        ->and(array_sum($sleeper->sleeps))->toBe(30)
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toBe('{"packages":[]}');
});

it('does not pin when a package failed, needs credentials, or the repositories are not wired', function (array $run, array $repositories, array $composerRepositories, string $expected) {
    linkedProject($this->workDir, $this->store, $composerRepositories);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 3, 'packages_already_deposited' => 1] + $run]);
    $this->transport->queueJson(rewrittenLockResponse($repositories));

    $written = runAutoDeposit($this->transport, $this->store, $this->workDir);

    expect($written)->toContain('composer.lock was left as it is because '.$expected)
        ->and($written)->toContain('composer vaults:deposit --write')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toBe('{"packages":[]}');
})->with([
    'a failed package' => [
        ['packages_deposited' => 2, 'packages_failed' => 1, 'items' => [['status' => 'failed', 'reason' => 'not_found', 'package' => 'a/one', 'version' => 'v1']]],
        [],
        [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
        '1 package did not deposit',
    ],
    'credentials needed' => [
        ['packages_deposited' => 2, 'packages_failed' => 1, 'items' => [['status' => 'failed', 'reason' => 'credentials_required', 'host' => 'satis.dedoc.co', 'package' => 'dedoc/scramble-pro', 'version' => 'v1']]],
        [],
        [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
        'credentials are needed for satis.dedoc.co',
    ],
    'public repository missing' => [
        ['packages_deposited' => 3],
        [],
        [],
        'the Vaults repository is not in composer.json yet',
    ],
    'private repository missing' => [
        ['packages_deposited' => 3, 'items' => [['status' => 'deposited', 'private' => true, 'package' => 'acme/own-lib', 'version' => 'v1']]],
        ['private' => ['type' => 'composer', 'url' => 'https://private.vaults-edge.net']],
        [['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc']],
        'this project is not set up to install its private packages from Vaults yet',
    ],
]);

it('pins private packages too once the private repository is wired', function () {
    linkedProject($this->workDir, $this->store, [
        ['type' => 'composer', 'url' => 'https://private.vaults-edge.net'],
        ['type' => 'composer', 'url' => 'https://repo.vaults-edge.net/repo/projects/abc'],
    ]);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);
    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 3, 'packages_deposited' => 3, 'packages_already_deposited' => 2, 'items' => [['status' => 'deposited', 'private' => true, 'package' => 'acme/own-lib', 'version' => 'v1']]]]);
    $this->transport->queueJson(rewrittenLockResponse(['private' => ['type' => 'composer', 'url' => 'https://private.vaults-edge.net']]));

    expect(runAutoDeposit($this->transport, $this->store, $this->workDir))->toContain('✓ composer.lock now installs from Vaults.')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toContain('"pinned": true');
});

it('only deposits in the background when pinning is switched off', function (array $extra, ?string $environment) {
    linkedProject($this->workDir, $this->store);

    if ($environment !== null) {
        putenv('VAULTS_AUTO_PIN='.$environment);
    }

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);

    $sleeper = new FakeSleeper;
    $written = runAutoDeposit($this->transport, $this->store, $this->workDir, $extra, $sleeper);

    putenv('VAULTS_AUTO_PIN');

    expect($written)->toContain('depositing composer.lock in the background')
        ->and($sleeper->sleeps)->toBe([])
        ->and($this->transport->requests)->toHaveCount(1)
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toBe('{"packages":[]}');
})->with([
    'auto-pin false' => [['vaults' => ['auto-pin' => false]], null],
    'a wait of zero' => [['vaults' => ['auto-pin-wait' => 0]], null],
    'the environment' => [[], '0'],
]);

it('waits only as long as the project allows, and never beyond two minutes', function (int $configured, int $expected) {
    linkedProject($this->workDir, $this->store);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202);

    foreach (range(1, 70) as $poll) {
        $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'running', 'packages_total' => 500, 'packages_already_deposited' => 0]]);
    }

    $sleeper = new FakeSleeper;
    runAutoDeposit($this->transport, $this->store, $this->workDir, ['vaults' => ['auto-pin-wait' => $configured]], $sleeper);

    expect(array_sum($sleeper->sleeps))->toBe($expected);
})->with([[10, 10], [600, 120]]);

it('says so when the lock already installs from Vaults', function () {
    linkedProject($this->workDir, $this->store);

    $pinned = (new LockContentHash)->refresh('{"content-hash": "0000000000000000000000000000dead", "packages": [], "pinned": true}', $this->workDir.'/composer.json');
    file_put_contents($this->workDir.'/composer.lock', $pinned);

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 3, 'packages_deposited' => 3, 'packages_already_deposited' => 3]], 202);
    $this->transport->queueJson(rewrittenLockResponse());

    $written = runAutoDeposit($this->transport, $this->store, $this->workDir);

    expect($written)->toContain('✓ All 3 packages in composer.lock are already in Vaults.')
        ->and($written)->toContain('✓ composer.lock already installs from Vaults.')
        ->and((string) file_get_contents($this->workDir.'/composer.lock'))->toBe($pinned);
});

it('never breaks an update when the api fails part way through pinning', function (int $failAt) {
    linkedProject($this->workDir, $this->store);

    $responses = [
        [['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 0]], 202],
        [['data' => ['uuid' => 'run-uuid', 'status' => 'completed', 'packages_total' => 3, 'packages_deposited' => 3, 'packages_already_deposited' => 2]], 200],
        [rewrittenLockResponse(), 200],
    ];

    foreach ($responses as $index => [$body, $status]) {
        $index === $failAt
            ? $this->transport->queueJson(['message' => 'boom'], 500)
            : $this->transport->queueJson($body, $status);
    }

    runAutoDeposit($this->transport, $this->store, $this->workDir);

    expect((string) file_get_contents($this->workDir.'/composer.lock'))->toBe('{"packages":[]}');
})->with([0, 1, 2]);
