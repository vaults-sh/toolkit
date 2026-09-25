<?php

declare(strict_types=1);

namespace Vaults\Report;

use Vaults\Result\DepositRun;
use Vaults\Result\DepositRunItem;

final readonly class DepositReport
{
    public function __construct(private string $commandPrefix) {}

    public function summary(DepositRun $run): string
    {
        $line = 'Deposited: '.$run->packagesDeposited.' · Skipped: '.$run->packagesSkipped.' · Failed: '.$run->packagesFailed;

        if ($run->packagesPrivate > 0) {
            $line .= ' · Private (served from your team repository): '.$run->packagesPrivate;
        }

        return $line;
    }

    public function coverage(DepositRun $run): string
    {
        return 'Coverage: '.$run->depositPercentage().'% of '.$run->coverablePackages().' coverable packages.';
    }

    /**
     * Lines describing every package that did not deposit, grouped by reason, with the fix for each.
     *
     * @return list<string>
     */
    public function problems(DepositRun $run): array
    {
        $groups = [];

        foreach ([...$run->failedItems(), ...$run->skippedItems()] as $item) {
            $groups[$this->groupKey($item)][] = $item;
        }

        if ($groups === []) {
            return [];
        }

        $lines = ['', 'Not deposited:'];

        foreach ($groups as $key => $items) {
            $lines = [...$lines, ...$this->group($key, $items)];
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function privateHint(DepositRun $run, bool $privateRepositoryConfigured): array
    {
        $private = $run->depositedPrivateItems();

        if ($private === [] || $privateRepositoryConfigured) {
            return [];
        }

        return [
            '',
            count($private) === 1
                ? $private[0]->package.' was deposited as a private package for your team.'
                : count($private).' packages were deposited as private packages for your team.',
            'Run '.$this->commandPrefix.'private:link so this project can install them from your private repository.',
        ];
    }

    private function groupKey(DepositRunItem $item): string
    {
        if ($item->needsCredentials() && $item->host !== null) {
            return $item->reason.'|'.$item->host;
        }

        return $item->reason ?? ($item->isSkipped() ? 'skipped' : 'failed');
    }

    /**
     * @param  list<DepositRunItem>  $items
     * @return list<string>
     */
    private function group(string $key, array $items): array
    {
        [$reason, $host] = array_pad(explode('|', $key, 2), 2, null);
        $first = $items[0];

        $lines = match ($reason) {
            'credentials_required' => $this->packages($items, 'needs credentials for '.$host),
            'credentials_rejected' => $this->packages($items, 'credentials for '.$host.' were rejected'),
            'private_repository' => $this->packages($items, 'private repository, not hosted on Vaults yet'),
            'private_served_from_team' => [$this->count($items, 'private package', 'private packages').' served from your team repository, not counted against coverage.'],
            'source_only' => [$this->count($items, 'package publishes', 'packages publish').' no archive (source-only), so there is nothing to mirror yet.'],
            'path_repository' => [$this->count($items, 'local path dependency', 'local path dependencies').' skipped.'],
            'missing_dist' => $this->packages($items, 'no dist url in composer.lock'),
            default => $this->packages($items, $first->error ?? ($first->isSkipped() ? 'skipped' : 'failed')),
        };

        $hint = match ($reason) {
            'credentials_required' => '    Give Vaults the credentials from auth.json: '.$this->commandPrefix.'repositories:add '.$host,
            'credentials_rejected' => '    Update them with: '.$this->commandPrefix.'repositories:add '.$host,
            'private_repository' => '    Connect the repository under Team settings → Sources, or run '.$this->commandPrefix.'open',
            default => null,
        };

        return $hint === null ? $lines : [...$lines, $hint];
    }

    /**
     * @param  list<DepositRunItem>  $items
     * @return list<string>
     */
    private function packages(array $items, string $reason): array
    {
        $shown = array_slice($items, 0, 10);
        $lines = array_map(fn (DepositRunItem $item): string => '  '.$item->package.' '.$item->version.'  '.$reason, $shown);

        if (count($items) > count($shown)) {
            $lines[] = '  … and '.(count($items) - count($shown)).' more with the same reason';
        }

        return $lines;
    }

    /**
     * @param  list<DepositRunItem>  $items
     */
    private function count(array $items, string $singular, string $plural): string
    {
        $count = count($items);

        return '  '.$count.' '.($count === 1 ? $singular : $plural);
    }
}
