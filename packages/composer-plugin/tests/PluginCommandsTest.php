<?php

declare(strict_types=1);

use Composer\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeIO;
use Tests\Support\FakeTransport;
use Vaults\Auth\TokenStore;
use Vaults\Composer\ComposerConfigWriter;
use Vaults\ComposerPlugin\Commands\ConnectCommand;
use Vaults\ComposerPlugin\Commands\DoctorCommand;
use Vaults\ComposerPlugin\Commands\InitCommand;
use Vaults\ComposerPlugin\Commands\LoginCommand;
use Vaults\ComposerPlugin\Commands\LogoutCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysCreateCommand;
use Vaults\ComposerPlugin\Commands\PrivateKeysRevokeCommand;
use Vaults\ComposerPlugin\Commands\PrivateLinkCommand;
use Vaults\ComposerPlugin\Commands\StatusCommand;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\ComposerPlugin\VaultsPlugin;
use Vaults\Diagnostics\EdgeProbe;
use Vaults\Support\FakeSleeper;
use Vaults\VaultsClient;

beforeEach(function () {
    $this->transport = new FakeTransport;
    $this->workDir = sys_get_temp_dir().'/vaults-plugin-tests/'.uniqid();
    mkdir($this->workDir, 0755, true);

    $this->store = new TokenStore($this->workDir.'/config.json');
    $this->store->save('test-token');
    $this->io = new FakeIO;
    $this->probe = new class extends EdgeProbe
    {
        /** @var list<string> */
        public array $answers = ['x.sni.global.fastly.net'];

        public bool $healthy = true;

        public function resolve(string $hostname): array
        {
            return $this->answers;
        }

        public function healthCheck(string $hostname): bool
        {
            return $this->healthy;
        }
    };
    $this->writer = new class extends ComposerConfigWriter
    {
        public ?string $privateUrl = null;

        public function addPrivateRepository(string $directory, string $url): bool
        {
            $this->privateUrl = $url;

            return true;
        }
    };

    $this->tester = function (string $commandClass): CommandTester {
        /** @var VaultsCommand $command */
        $command = new $commandClass(
            new VaultsClient(null, 'https://vaults.test', $this->transport, 'https://auth.vaults.test'),
            $this->store,
            $this->workDir,
            new FakeSleeper,
            $this->io,
            $this->probe,
            $this->writer,
        );
        $command->setApplication(new Application);

        return new CommandTester($command);
    };
});

it('registers every command under the vaults namespace with deposit kept as an alias', function () {
    $plugin = new VaultsPlugin;
    $provider = new (array_values($plugin->getCapabilities())[0]);
    $commands = $provider->getCommands();
    $names = array_map(fn ($command): string => (string) $command->getName(), $commands);

    expect($names)->toBe([
        'vaults:deposit',
        'vaults:login',
        'vaults:logout',
        'vaults:init',
        'vaults:status',
        'vaults:doctor',
        'vaults:connect',
        'vaults:private:link',
        'vaults:private:keys',
        'vaults:private:keys:create',
        'vaults:private:keys:revoke',
    ])->and($commands[0]->getAliases())->toBe(['deposit']);
});

it('logs in with a pasted token', function () {
    $this->store->clear();
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'team-uuid', 'name' => 'Acme']]]);

    $tester = ($this->tester)(LoginCommand::class);
    $exit = $tester->execute(['--token' => 'pasted-token']);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('Logged in to team: Acme')
        ->and($this->store->token())->toBe('pasted-token');
});

it('rejects an invalid pasted token', function () {
    $this->store->clear();
    $this->transport->queueJson(['message' => 'Unauthenticated.'], 401);

    $tester = ($this->tester)(LoginCommand::class);
    $exit = $tester->execute(['--token' => 'bad-token']);

    expect($exit)->toBe(1)
        ->and($tester->getDisplay())->toContain('That token was rejected')
        ->and($this->store->token())->toBeNull();
});

it('logs in through the device flow when interactive', function () {
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

    $tester = ($this->tester)(LoginCommand::class);
    $exit = $tester->execute([], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('ABCD-EFGH')
        ->and($this->store->token())->toBe('issued-token');
});

it('logs out', function () {
    $tester = ($this->tester)(LogoutCommand::class);

    expect($tester->execute([]))->toBe(0)
        ->and($this->store->token())->toBeNull();
});

it('initialises a directory by creating a project when none exist', function () {
    $this->transport->queueJson(['data' => []]);
    $this->transport->queueJson(['data' => ['uuid' => 'new-uuid', 'name' => 'fresh-app', 'repository_snippet' => [], 'repository_published' => false, 'deposit_percentage' => 0]], 201);
    $this->io->queue('');

    $tester = ($this->tester)(InitCommand::class);
    $exit = $tester->execute([], ['interactive' => true]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('Linked this directory to "fresh-app"')
        ->and(json_decode((string) file_get_contents($this->workDir.'/.vaults.json'), true)['project'])->toBe('new-uuid');
});

it('does nothing when init runs in an already linked directory', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');

    $tester = ($this->tester)(InitCommand::class);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('already linked');
});

it('shows project status from the manifest', function () {
    file_put_contents($this->workDir.'/.vaults.json', '{"project":"project-uuid"}');
    $this->transport->queueJson(['data' => [[
        'uuid' => 'project-uuid',
        'name' => 'checkout-api',
        'repository_snippet' => [],
        'repository_published' => true,
        'deposit_percentage' => 87.5,
        'latest_run' => ['uuid' => 'run', 'status' => 'completed', 'packages_total' => 8, 'packages_deposited' => 7],
    ]]]);

    $tester = ($this->tester)(StatusCommand::class);
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('checkout-api')
        ->and($tester->getDisplay())->toContain('87.5%')
        ->and($tester->getDisplay())->toContain('7/8');
});

it('fails status without a manifest when non-interactive', function () {
    $tester = ($this->tester)(StatusCommand::class);

    expect($tester->execute([], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('not linked');
});

it('reports a healthy doctor run', function () {
    file_put_contents($this->workDir.'/composer.lock', '{}');
    $this->transport->queueJson(['data' => ['status' => 'ok']]);
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'u', 'name' => 'Acme']]]);

    $tester = ($this->tester)(DoctorCommand::class);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('team: Acme')
        ->and($tester->getDisplay())->toContain('Everything looks healthy.');
});

it('fails doctor when the edge is unhealthy', function () {
    $this->probe->answers = [];
    $this->transport->queueJson(['data' => ['status' => 'ok']]);
    $this->transport->queueJson(['data' => ['team' => ['uuid' => 'u', 'name' => 'Acme']]]);

    $tester = ($this->tester)(DoctorCommand::class);

    expect($tester->execute([]))->toBe(1)
        ->and($tester->getDisplay())->toContain('no answer')
        ->and($tester->getDisplay())->toContain('Some checks failed.');
});

it('points the connect command at the dashboard', function () {
    $tester = ($this->tester)(ConnectCommand::class);

    expect($tester->execute([], ['interactive' => false]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Connections')
        ->and($tester->getDisplay())->toContain('composer vaults:private:link');
});

it('links private packages by adding the repository and writing the bearer token', function () {
    $this->transport->queueJson(['data' => [
        'token' => 'vault-token-xyz',
        'host' => 'private.vaults-edge.net',
        'repository_url' => 'https://private.vaults-edge.net',
        'expires_at' => time() + 604800,
    ]]);

    $tester = ($this->tester)(PrivateLinkCommand::class);
    $exit = $tester->execute([]);

    $auth = json_decode((string) file_get_contents($this->workDir.'/auth.json'), true);

    expect($exit)->toBe(0)
        ->and($this->writer->privateUrl)->toBe('https://private.vaults-edge.net')
        ->and($auth['bearer']['private.vaults-edge.net'])->toBe('vault-token-xyz')
        ->and($tester->getDisplay())->toContain('Do not commit auth.json');
});

it('writes the private token to the global auth.json with --global', function () {
    $composerHome = $this->workDir.'/composer-home';
    putenv('COMPOSER_HOME='.$composerHome);
    $this->transport->queueJson(['data' => [
        'token' => 'global-token',
        'host' => 'private.vaults-edge.net',
        'repository_url' => 'https://private.vaults-edge.net',
        'expires_at' => null,
    ]]);

    $tester = ($this->tester)(PrivateLinkCommand::class);
    $exit = $tester->execute(['--global' => true]);

    putenv('COMPOSER_HOME');

    expect($exit)->toBe(0)
        ->and(json_decode((string) file_get_contents($composerHome.'/auth.json'), true)['bearer']['private.vaults-edge.net'])->toBe('global-token');
});

it('fails private commands when not authenticated and non-interactive', function () {
    $this->store->clear();

    $tester = ($this->tester)(PrivateLinkCommand::class);

    expect($tester->execute([], ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay())->toContain('composer vaults:login');
});

it('lists private access keys', function () {
    $this->transport->queueJson(['data' => [
        ['uuid' => 'k1', 'name' => 'GitHub Actions', 'project' => null, 'packages' => null, 'expires_at' => '2027-01-01T00:00:00Z', 'created_at' => null],
    ]]);

    $tester = ($this->tester)(PrivateKeysCommand::class);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('GitHub Actions')
        ->and($tester->getDisplay())->toContain('2027-01-01');
});

it('creates a private access key and prints the snippet once', function () {
    $this->transport->queueJson([
        'data' => ['uuid' => 'k2', 'name' => 'Client deploy', 'project' => null, 'packages' => ['acme/billing'], 'expires_at' => '2027-03-01T00:00:00Z', 'created_at' => null],
        'token' => 'key-token',
        'host' => 'private.vaults-edge.net',
    ], 201);

    $tester = ($this->tester)(PrivateKeysCreateCommand::class);
    $exit = $tester->execute(['name' => 'Client deploy', '--package' => ['acme/billing'], '--expires' => '90']);

    $body = json_decode((string) $this->transport->requests[0]->body, true);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('key-token')
        ->and($tester->getDisplay())->toContain('COMPOSER_AUTH')
        ->and($body['packages'])->toBe(['acme/billing'])
        ->and($body['expires_in_days'])->toBe(90);
});

it('writes a created key straight into auth.json with --write', function () {
    $this->transport->queueJson([
        'data' => ['uuid' => 'k3', 'name' => 'CI', 'project' => null, 'packages' => null, 'expires_at' => null, 'created_at' => null],
        'token' => 'ci-token',
        'host' => 'private.vaults-edge.net',
    ], 201);

    $tester = ($this->tester)(PrivateKeysCreateCommand::class);

    expect($tester->execute(['name' => 'CI', '--write' => true]))->toBe(0)
        ->and(json_decode((string) file_get_contents($this->workDir.'/auth.json'), true)['bearer']['private.vaults-edge.net'])->toBe('ci-token')
        ->and($tester->getDisplay())->not->toContain('ci-token');
});

it('rejects an out-of-range expiry before calling the api', function () {
    $tester = ($this->tester)(PrivateKeysCreateCommand::class);

    expect($tester->execute(['name' => 'CI', '--expires' => '900']))->toBe(1)
        ->and($this->transport->requests)->toBe([]);
});

it('revokes a private access key', function () {
    $this->transport->queueJson([], 204);

    $tester = ($this->tester)(PrivateKeysRevokeCommand::class);

    expect($tester->execute(['key' => 'k1']))->toBe(0)
        ->and($tester->getDisplay())->toContain('Key revoked')
        ->and($this->transport->requests[0]->method)->toBe('DELETE');
});
