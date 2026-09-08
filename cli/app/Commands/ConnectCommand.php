<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ConnectCommand extends Command
{
    protected $signature = 'connect';

    protected $description = 'Open the Vaults dashboard to connect a git provider and choose repositories to host';

    public function handle(): int
    {
        $appUrl = getenv('VAULTS_APP_URL');
        $base = is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/') : 'https://vaults.sh';

        $this->line('Connecting a provider happens in your browser.');
        $this->line('Open '.$base.', add the provider under Team settings -> Connections, then pick the');
        $this->line('repositories to host on the Private Packages page.');

        if ($this->input->isInteractive()) {
            $this->openBrowser($base);
        }

        $this->newLine();
        $this->line('Once a repository is hosted, run vaults private:link here to install its packages.');

        return self::SUCCESS;
    }

    private function openBrowser(string $url): void
    {
        if (getenv('VAULTS_NO_BROWSER') !== false) {
            return;
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        @exec($command.' '.escapeshellarg($url).' > /dev/null 2>&1 &');
    }
}
