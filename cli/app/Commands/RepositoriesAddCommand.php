<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Composer\AuthJson;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\VaultsClient;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class RepositoriesAddCommand extends Command
{
    protected $signature = 'repositories:add
        {host : The repository host, e.g. satis.example.com}
        {--type= : http-basic or bearer}
        {--username= : Username for http-basic}
        {--secret= : Password or token (prefer auth.json or the prompt over passing this on the command line)}
        {--from-auth : Take the credentials from auth.json without asking}';

    protected $description = 'Give Vaults the credentials for a paid or private Composer repository so its packages can be deposited';

    public function handle(VaultsClient $client, AuthJson $authJson): int
    {
        $host = strtolower(trim((string) $this->argument('host')));
        $type = $this->option('type');
        $username = $this->option('username');
        $secret = $this->option('secret');

        $found = $authJson->credentialsFor($host, (string) getcwd());

        if (! is_string($secret) || $secret === '') {
            if ($found !== null && ($this->option('from-auth') || ! $this->input->isInteractive() || confirm('Use the credentials for '.$host.' from '.$found['source'].'?'))) {
                $type = $found['type'];
                $username = $found['username'];
                $secret = $found['secret'];
            } elseif (! $this->input->isInteractive()) {
                $this->error('No credentials for '.$host.' in auth.json. Pass --type, --username and --secret, or run interactively.');

                return self::FAILURE;
            } else {
                $type = is_string($type) && $type !== '' ? $type : select('Credential type', ['http-basic' => 'HTTP basic (username and password)', 'bearer' => 'Bearer token']);
                $username = $type === 'http-basic' ? (is_string($username) && $username !== '' ? $username : text('Username', required: true)) : null;
                $secret = password($type === 'bearer' ? 'Token' : 'Password', required: true);
            }
        }

        $type = is_string($type) && $type !== '' ? $type : ($username !== null ? 'http-basic' : 'bearer');

        if (! in_array($type, ['http-basic', 'bearer'], true)) {
            $this->error('--type must be http-basic or bearer.');

            return self::FAILURE;
        }

        if ($type === 'http-basic' && (! is_string($username) || $username === '')) {
            $this->error('HTTP basic credentials need --username.');

            return self::FAILURE;
        }

        try {
            $credential = $client->storeRepositoryCredential($host, $type, (string) $secret, $type === 'http-basic' ? $username : null);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Saved '.$credential->typeLabel().' credentials for '.$credential->host.'. Vaults will use them to deposit that host\'s packages privately for your team.');
        $this->line('By saving them you confirm your team is licensed for the packages on this host. Run vaults deposit to pick them up.');

        return self::SUCCESS;
    }
}
