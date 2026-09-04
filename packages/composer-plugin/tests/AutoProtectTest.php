<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Tests\Support\FakeIO;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\ComposerPlugin\VaultsPlugin;
use Vaults\VaultsClient;

function testablePlugin(FakeTransport $transport, TokenStore $store, string $dir): VaultsPlugin
{
    return new class($transport, $store, $dir) extends VaultsPlugin
    {
        public function __construct(private FakeTransport $t, private TokenStore $s, private string $d) {}

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

function updateEvent(array $extra = []): Event
{
    $rootPackage = new RootPackage('vaults-test/root', '1.0.0.0', '1.0.0');
    $rootPackage->setExtra($extra);

    $composer = new Composer;
    $composer->setPackage($rootPackage);

    return new Event(ScriptEvents::POST_UPDATE_CMD, $composer, new FakeIO);
}

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->workDir = sys_get_temp_dir().'/vaults-autoprotect-tests/'.uniqid();
    mkdir($this->workDir, 0755, true);
    $this->store = new TokenStore($this->workDir.'/config.json');
});

it('subscribes to the post-update event', function () {
    expect(VaultsPlugin::getSubscribedEvents())->toHaveKey(ScriptEvents::POST_UPDATE_CMD);
});

it('fires a protection run after update when linked and authenticated', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['data' => ['uuid' => 'run-uuid', 'status' => 'pending', 'packages_total' => 7]], 202);

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());

    $request = $this->transport->requests[0] ?? null;

    expect($request?->url)->toBe('https://vaults.test/api/v1/projects/project-uuid/protect')
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

it('respects the auto-protect opt-out', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    testablePlugin($this->transport, $this->store, $this->workDir)
        ->onPostUpdate(updateEvent(['vaults' => ['auto-protect' => false]]));

    expect($this->transport->requests)->toBeEmpty();
});

it('never throws even when the api fails', function () {
    $this->store->save('test-token');
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    file_put_contents($this->workDir.'/composer.lock', '{"packages":[]}');

    $this->transport->queueJson(['message' => 'boom'], 500);

    testablePlugin($this->transport, $this->store, $this->workDir)->onPostUpdate(updateEvent());
})->throwsNoExceptions();
