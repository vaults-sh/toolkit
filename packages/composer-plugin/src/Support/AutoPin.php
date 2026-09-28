<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Support;

use Throwable;
use Vaults\Composer\LockContentHash;
use Vaults\Report\DepositReport;
use Vaults\Result\DepositRun;
use Vaults\Support\Sleeper;
use Vaults\VaultsClient;

final readonly class AutoPin
{
    public const int DefaultWaitSeconds = 30;

    public const int MaximumWaitSeconds = 120;

    private const int PollSeconds = 2;

    private const string Background = '<info>Vaults:</info> depositing composer.lock in the background. Run "composer vaults:deposit --write" to pin composer.lock to Vaults.';

    public function __construct(
        private VaultsClient $client,
        private Sleeper $sleeper,
    ) {}

    /**
     * @return list<string>
     */
    public function __invoke(string $projectUuid, string $directory, int $waitSeconds): array
    {
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';
        $lock = (string) file_get_contents($lockPath);

        $run = $this->client->deposit($projectUuid, $lock);

        if ($waitSeconds <= 0) {
            return [self::Background];
        }

        try {
            $run = $this->await($run, min($waitSeconds, self::MaximumWaitSeconds));
        } catch (Throwable) {
            return [self::Background];
        }

        if (! $run->isFinished()) {
            return [self::Background];
        }

        $lines = [];
        $scope = (new DepositReport('composer vaults:'))->scope($run);

        if ($scope !== null) {
            $lines[] = '<info>Vaults:</info> '.$scope;
        }

        $reason = $this->reasonNotToPin($run);

        if ($reason !== null) {
            return [...$lines, $this->leftAlone($reason)];
        }

        try {
            $rewritten = $this->client->getRewrittenLock($run->uuid);
        } catch (Throwable) {
            return [...$lines, self::Background];
        }

        if ($rewritten->composerLock === '') {
            return [...$lines, self::Background];
        }

        if (($rewritten->projectRepository['url'] ?? '') !== '' && ! ComposerJsonRepositories::has($directory, $rewritten->projectRepository)) {
            return [...$lines, $this->leftAlone('the Vaults repository is not in composer.json yet')];
        }

        if ($run->depositedPrivateItems() !== [] && ! ComposerJsonRepositories::has($directory, $rewritten->privateRepository)) {
            return [...$lines, $this->leftAlone('this project is not set up to install its private packages from Vaults yet')];
        }

        $pinned = (new LockContentHash)->refresh($rewritten->composerLock, $directory.DIRECTORY_SEPARATOR.'composer.json');

        if ($pinned === $lock) {
            return [...$lines, '<info>Vaults:</info> <fg=green>✓</> composer.lock already installs from Vaults.'];
        }

        file_put_contents($lockPath, $pinned);

        return [...$lines, '<info>Vaults:</info> <fg=green>✓</> composer.lock now installs from Vaults.'];
    }

    private function await(DepositRun $run, int $waitSeconds): DepositRun
    {
        $waited = 0;

        while (! $run->isFinished() && $waited < $waitSeconds) {
            $this->sleeper->sleep(self::PollSeconds);
            $waited += self::PollSeconds;

            $run = $this->client->getRun($run->uuid);
        }

        return $run;
    }

    private function reasonNotToPin(DepositRun $run): ?string
    {
        if ($run->status !== 'completed') {
            return 'the deposit run failed';
        }

        if ($run->hostsNeedingCredentials() !== []) {
            return 'credentials are needed for '.implode(', ', $run->hostsNeedingCredentials());
        }

        if ($run->packagesFailed > 0) {
            return $run->packagesFailed.' '.($run->packagesFailed === 1 ? 'package' : 'packages').' did not deposit';
        }

        return null;
    }

    private function leftAlone(string $reason): string
    {
        return '<comment>Vaults:</comment> composer.lock was left as it is because '.$reason.'. Run "composer vaults:deposit --write" to finish.';
    }
}
