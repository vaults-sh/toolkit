<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Auth\DeviceFlow;
use Vaults\Auth\TokenStore;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

class LoginCommand extends Command
{
    protected $signature = 'login {--token= : Authenticate with an existing team API token}';

    protected $description = 'Authenticate the CLI with your Vaults team';

    public function handle(VaultsClient $client, TokenStore $store): int
    {
        $pasted = $this->option('token');

        if (is_string($pasted) && $pasted !== '') {
            return $this->storeValidated($client, $store, $pasted);
        }

        $flow = new DeviceFlow($client);

        try {
            $pair = $flow->start((string) (gethostname() ?: 'vaults-cli'));
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('First, copy your device code: '.$pair->userCode);
        $this->line('Then approve it at: '.$pair->verificationUri);
        $this->newLine();
        $this->openBrowser($pair->verificationUriComplete);

        $result = \Laravel\Prompts\spin(fn () => $flow->await($pair), 'Waiting for approval in the browser...');

        if (! $result->isApproved() || $result->token === null) {
            $this->error('The device code expired before it was approved. Run vaults login to try again.');

            return self::FAILURE;
        }

        $store->save($result->token, $result->team);

        $this->info('Logged in to team: '.($result->team?->name ?? 'unknown'));

        return self::SUCCESS;
    }

    private function storeValidated(VaultsClient $client, TokenStore $store, string $token): int
    {
        try {
            $team = $client->withToken($token)->whoami();
        } catch (VaultsException $exception) {
            $this->error('That token was rejected: '.$exception->getMessage());

            return self::FAILURE;
        }

        $store->save($token, $team);

        $this->info('Logged in to team: '.($team->name ?? 'unknown'));

        return self::SUCCESS;
    }

    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        @exec($command.' '.escapeshellarg($url).' > /dev/null 2>&1 &');
    }
}
