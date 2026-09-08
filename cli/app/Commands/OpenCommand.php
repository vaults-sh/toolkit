<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Vaults\Project\ProjectManifest;

class OpenCommand extends Command
{
    protected $signature = 'open';

    protected $description = 'Open the Vaults dashboard in your browser';

    public function handle(ProjectManifest $manifest): int
    {
        $appUrl = getenv('VAULTS_APP_URL');
        $base = is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/') : 'https://vaults.sh';
        $url = $base.'/dashboard';

        $this->line('Opening '.$url);

        if ($manifest->load((string) getcwd()) === null) {
            $this->line('This directory is not linked to a project; run vaults init to link it.');
        }

        if ($this->input->isInteractive()) {
            $this->openBrowser($url);
        }

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
