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
        $this->line('Open '.$base.' and go to your team\'s Connections page.');

        if ($this->input->isInteractive()) {
            $this->openBrowser($base);
        }

        $this->newLine();
        $this->line('Once a provider is connected and repositories are selected, run vaults private:link here to install them.');

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
