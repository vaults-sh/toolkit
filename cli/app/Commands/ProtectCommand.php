<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesProject;
use App\Services\ComposerConfigWriter;
use App\Services\LockContentHash;
use LaravelZero\Framework\Commands\Command;
use Vaults\Exception\AuthenticationException;
use Vaults\Exception\VaultsException;
use Vaults\Project\ProjectManifest;
use Vaults\Result\CheckPackage;
use Vaults\Result\ProtectionRun;
use Vaults\Result\RewrittenLock;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

class ProtectCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'protect
        {--check : Report protection status without starting a run}
        {--write : Overwrite composer.lock with the rewritten Vaults version}
        {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'Protect the dependencies in composer.lock with Vaults';

    public function handle(VaultsClient $client, ProjectManifest $manifest): int
    {
        $directory = (string) getcwd();
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            $this->error('No composer.lock found in '.$directory.'.');

            return self::FAILURE;
        }

        $lock = (string) file_get_contents($lockPath);

        try {
            $projectUuid = $this->resolveProject($client, $manifest, $directory);

            if ($projectUuid === null) {
                return self::FAILURE;
            }

            if ($this->option('check')) {
                return $this->check($client, $projectUuid, $lock);
            }

            return $this->protect($client, $projectUuid, $lock, $lockPath, $directory);
        } catch (AuthenticationException) {
            $this->error('Not authenticated. Run vaults login first.');

            return self::FAILURE;
        } catch (VaultsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function check(VaultsClient $client, string $projectUuid, string $lock): int
    {
        $result = spin(fn () => $client->protectCheck($projectUuid, $lock), 'Checking protection status...');

        $this->table(
            ['Package', 'Version', 'Protected', 'Security'],
            array_map(fn (CheckPackage $package): array => [
                $package->name,
                $package->version,
                $package->protected ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $this->securityLabel($package->securityStatus),
            ], $result->packages),
        );

        $this->line($result->protected.'/'.$result->total.' protected, '.$result->unprotected.' unprotected.');

        if (! $result->isFullyProtected()) {
            $this->warn('Run vaults protect to protect the remaining packages.');

            return self::FAILURE;
        }

        $this->info('All packages are protected.');

        return self::SUCCESS;
    }

    private function protect(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, string $directory): int
    {
        $run = spin(fn () => $client->protect($projectUuid, $lock), 'Starting the protection run...');

        $run = $this->awaitRun($client, $this->laravel->make(Sleeper::class), $run);

        $this->line('Protected: '.$run->packagesProtected.' · Skipped: '.$run->packagesSkipped.' · Failed: '.$run->packagesFailed);

        if ($run->status !== 'completed') {
            $this->error('The protection run failed. See the dashboard for details.');

            return self::FAILURE;
        }

        $rewritten = spin(fn () => $client->getRewrittenLock($run->uuid), 'Fetching the rewritten lock...');

        $this->offerRepositoryWiring($rewritten, $directory);

        if ($this->option('write')) {
            file_put_contents($lockPath, resolve(LockContentHash::class)->refresh($rewritten->composerLock, $directory.DIRECTORY_SEPARATOR.'composer.json'));
            $this->info('composer.lock rewritten to install from Vaults. Run composer install.');
        } else {
            $this->line('Run vaults protect --write to rewrite composer.lock, then composer install.');
        }

        return self::SUCCESS;
    }

    private function awaitRun(VaultsClient $client, Sleeper $sleeper, ProtectionRun $run): ProtectionRun
    {
        $run = spin(function () use ($client, $sleeper, $run): ProtectionRun {
            while (! $run->isFinished() && $run->packagesTotal === 0) {
                $sleeper->sleep(1);

                $run = $client->getRun($run->uuid);
            }

            return $run;
        }, 'Analysing composer.lock...');

        if ($run->isFinished()) {
            return $run;
        }

        $total = $run->packagesTotal;
        $progress = progress(label: 'Protecting '.$total.' packages...', steps: $total);
        $progress->start();
        $reported = 0;

        while (! $run->isFinished()) {
            $sleeper->sleep(2);

            $run = $client->getRun($run->uuid);

            $done = min($total, $run->packagesProtected + $run->packagesSkipped + $run->packagesFailed);

            if ($done > $reported) {
                $progress->hint($run->packagesProtected.' protected · '.$run->packagesSkipped.' skipped · '.$run->packagesFailed.' failed');
                $progress->advance($done - $reported);
                $reported = $done;
            }
        }

        $progress->finish();

        return $run;
    }

    private function offerRepositoryWiring(RewrittenLock $rewritten, string $directory): void
    {
        $url = $rewritten->projectRepository['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return;
        }

        if (resolve(ComposerConfigWriter::class)->hasRepository($directory, $url)) {
            $this->line('<fg=green>✓</> The Vaults repository is already configured in composer.json.');

            return;
        }

        $snippet = (string) json_encode($rewritten->projectRepository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->input->isInteractive()) {
            $this->line('This will be added to the "repositories" section of composer.json:');
            $this->line('<fg=gray>'.$snippet.'</>');

            if (confirm('Add it now?')) {
                if (resolve(ComposerConfigWriter::class)->addRepository($directory, $url)) {
                    $this->info('composer.json updated, commit it along with .vaults.json.');

                    return;
                }

                $this->warn('Could not update composer.json automatically.');
            }
        }

        $this->line('Add this to the "repositories" section of composer.json:');
        $this->line($snippet);
    }

    private function securityLabel(?string $status): string
    {
        return match ($status) {
            'clear' => '<info>clear</info>',
            'flagged' => '<comment>flagged</comment>',
            'blocked' => '<error>blocked</error>',
            null => '-',
            default => $status,
        };
    }
}
