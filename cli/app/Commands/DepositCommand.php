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
use Vaults\Result\DepositRun;
use Vaults\Result\RewrittenLock;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

class DepositCommand extends Command
{
    use ResolvesProject;

    protected $signature = 'deposit
        {--check : Report deposit status without starting a run}
        {--write : Overwrite composer.lock with the rewritten Vaults version}
        {--project= : Project UUID (overrides .vaults.json)}';

    protected $description = 'Deposit the dependencies in composer.lock with Vaults';

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

            return $this->deposit($client, $projectUuid, $lock, $lockPath, $directory);
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
        $result = spin(fn () => $client->depositCheck($projectUuid, $lock), 'Checking deposit status...');

        $this->table(
            ['Package', 'Version', 'Deposited', 'Security'],
            array_map(fn (CheckPackage $package): array => [
                $package->name,
                $package->version,
                $package->deposited ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $this->securityLabel($package->securityStatus),
            ], $result->packages),
        );

        $this->line($result->deposited.'/'.$result->total.' deposited, '.$result->undeposited.' undeposited.');

        if (! $result->isFullyDeposited()) {
            $this->warn('Run vaults deposit to deposit the remaining packages.');

            return self::FAILURE;
        }

        $this->info('All packages are deposited.');

        return self::SUCCESS;
    }

    private function deposit(VaultsClient $client, string $projectUuid, string $lock, string $lockPath, string $directory): int
    {
        $run = spin(fn () => $client->deposit($projectUuid, $lock), 'Starting the deposit run...');

        $run = $this->awaitRun($client, $this->laravel->make(Sleeper::class), $run);

        $this->line('Deposited: '.$run->packagesDeposited.' · Skipped: '.$run->packagesSkipped.' · Failed: '.$run->packagesFailed);

        if ($run->status !== 'completed') {
            $this->error('The deposit run failed. See the dashboard for details.');

            return self::FAILURE;
        }

        $rewritten = spin(fn () => $client->getRewrittenLock($run->uuid), 'Fetching the rewritten lock...');

        $this->offerRepositoryWiring($rewritten, $directory);

        if ($this->option('write')) {
            file_put_contents($lockPath, resolve(LockContentHash::class)->refresh($rewritten->composerLock, $directory.DIRECTORY_SEPARATOR.'composer.json'));
            $this->info('composer.lock rewritten to install from Vaults. Run composer install.');
        } else {
            $this->line('Run vaults deposit --write to rewrite composer.lock, then composer install.');
        }

        return self::SUCCESS;
    }

    private function awaitRun(VaultsClient $client, Sleeper $sleeper, DepositRun $run): DepositRun
    {
        $run = spin(function () use ($client, $sleeper, $run): DepositRun {
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
        $progress = progress(label: 'Depositing '.$total.' packages...', steps: $total);
        $progress->start();
        $reported = 0;

        while (! $run->isFinished()) {
            $sleeper->sleep(2);

            $run = $client->getRun($run->uuid);

            $done = min($total, $run->packagesDeposited + $run->packagesSkipped + $run->packagesFailed);

            if ($done > $reported) {
                $progress->hint($run->packagesDeposited.' deposited · '.$run->packagesSkipped.' skipped · '.$run->packagesFailed.' failed');
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
