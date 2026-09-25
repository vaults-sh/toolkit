<?php

declare(strict_types=1);

namespace Vaults\Report;

use Vaults\Result\DepositRun;
use Vaults\Result\DepositRunItem;

/**
 * Console lines shared by the CLI and the plugin. Symfony formatter tags are used for colour; both
 * surfaces strip them when the output is not a terminal.
 */
final readonly class DepositReport
{
    private const int MaxPackagesPerGroup = 10;

    public function __construct(private string $commandPrefix) {}

    public function summary(DepositRun $run): string
    {
        $line = 'Deposited <info>'.$run->packagesDeposited.'</info> · Skipped <comment>'.$run->packagesSkipped.'</comment> · Failed '
            .($run->packagesFailed > 0 ? '<fg=red>'.$run->packagesFailed.'</>' : '<info>0</info>');

        if ($run->packagesPrivate > 0) {
            $line .= ' · Private <fg=cyan>'.$run->packagesPrivate.'</> <fg=gray>(served from your team repository)</>';
        }

        return $line;
    }

    public function coverage(DepositRun $run): string
    {
        $percentage = $run->depositPercentage();
        $colour = $percentage === 100 ? 'green' : ($percentage >= 90 ? 'yellow' : 'red');

        return 'Coverage <fg='.$colour.'>'.$percentage.'%</> <fg=gray>of '.$run->coverablePackages().' coverable packages</>';
    }

    /**
     * The block shown before a run for hosts the developer holds credentials for locally.
     *
     * @param  list<array{host: string, packages: list<string>, credentials: array{type: string, username: string|null, secret: string, source: string}}>  $repositories
     * @return list<string>
     */
    public function privateRepositories(array $repositories): array
    {
        if ($repositories === []) {
            return [];
        }

        $width = max(array_map(fn (array $repository): int => mb_strlen($repository['host']), $repositories));
        $lines = ['', '<options=bold>Private repositories in composer.lock</>'];

        foreach ($repositories as $repository) {
            $count = count($repository['packages']);
            $shown = array_slice($repository['packages'], 0, 3);
            $packages = implode(', ', $shown).($count > count($shown) ? ' <fg=gray>and '.($count - count($shown)).' more</>' : '');
            $credentials = $repository['credentials'];
            $who = $credentials['type'].($credentials['username'] !== null ? ', '.$credentials['username'] : '');

            $lines[] = '  <fg=cyan>'.str_pad($repository['host'], $width).'</>  <comment>'.$count.' package'.($count === 1 ? '' : 's').'</>  '.$packages;
            $lines[] = '  '.str_repeat(' ', $width).'  <fg=gray>credentials in '.$credentials['source'].' ('.$who.')</>';
        }

        $lines[] = '';
        $lines[] = 'Vaults can use these credentials to deposit those packages privately for your team.';

        return $lines;
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

        $lines = ['', '<options=bold>Not deposited</>'];

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
                ? '<fg=cyan>'.$private[0]->package.'</> was deposited as a private package for your team.'
                : '<fg=cyan>'.count($private).' packages</> were deposited as private packages for your team.',
            '<fg=gray>→</> Run <options=bold>'.$this->commandPrefix.'private:link</> so this project can install them from your private repository.',
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
        $hostTag = '<fg=cyan>'.$host.'</>';

        $lines = match ($reason) {
            'credentials_required' => $this->packages($items, 'red', 'needs credentials for '.$hostTag),
            'credentials_rejected' => $this->packages($items, 'red', 'credentials for '.$hostTag.' were rejected'),
            'private_repository' => $this->packages($items, 'red', 'private repository, not hosted on Vaults yet'),
            'private_served_from_team' => [$this->count($items, 'cyan', 'private package', 'private packages').' served from your team repository, not counted against coverage'],
            'source_only' => [$this->count($items, 'yellow', 'package publishes', 'packages publish').' no archive (source-only), so there is nothing to mirror yet'],
            'path_repository' => [$this->count($items, 'yellow', 'local path dependency', 'local path dependencies').' skipped'],
            'missing_dist' => $this->packages($items, 'yellow', 'no dist url in composer.lock'),
            default => $this->packages($items, $first->isSkipped() ? 'yellow' : 'red', $first->error ?? ($first->isSkipped() ? 'skipped' : 'failed')),
        };

        $hint = match ($reason) {
            'credentials_required' => '<fg=gray>→</> '.$this->commandPrefix.'repositories:add '.$host.' <fg=gray>(uses the credentials in your auth.json)</>',
            'credentials_rejected' => '<fg=gray>→</> '.$this->commandPrefix.'repositories:add '.$host.' <fg=gray>(replaces the stored credentials)</>',
            'private_repository' => '<fg=gray>→</> Connect the repository under Team settings › Sources, or run '.$this->commandPrefix.'open',
            default => null,
        };

        return $hint === null ? $lines : [...$lines, '    '.$hint];
    }

    /**
     * @param  list<DepositRunItem>  $items
     * @return list<string>
     */
    private function packages(array $items, string $colour, string $reason): array
    {
        $shown = array_slice($items, 0, self::MaxPackagesPerGroup);
        $width = max(array_map(fn (DepositRunItem $item): int => mb_strlen($item->package.' '.$item->version), $shown));
        $marker = $colour === 'red' ? '✗' : '–';

        $lines = array_map(
            fn (DepositRunItem $item): string => '  <fg='.$colour.'>'.$marker.'</> '.str_pad($item->package.' <fg=gray>'.$item->version.'</>', $width + strlen('<fg=gray></>')).'  '.$reason,
            $shown,
        );

        if (count($items) > count($shown)) {
            $lines[] = '    <fg=gray>… and '.(count($items) - count($shown)).' more with the same reason</>';
        }

        return $lines;
    }

    /**
     * @param  list<DepositRunItem>  $items
     */
    private function count(array $items, string $colour, string $singular, string $plural): string
    {
        $count = count($items);

        return '  <fg='.$colour.'>–</> '.$count.' '.($count === 1 ? $singular : $plural);
    }
}
