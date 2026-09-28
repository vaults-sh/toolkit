<?php

declare(strict_types=1);

use Vaults\Report\DepositReport;
use Vaults\Result\DepositRun;

/**
 * @param  list<string>  $lines
 * @return list<string>
 */
function plain(array $lines): array
{
    return array_map(fn (string $line): string => (string) preg_replace('/<\/?[a-z=,;]*>/', '', $line), $lines);
}

function reportRun(array $items, int $failed = 0, int $skipped = 0, int $private = 0): DepositRun
{
    return DepositRun::fromArray([
        'uuid' => 'run',
        'status' => 'completed',
        'packages_total' => count($items) + 5,
        'packages_deposited' => 5,
        'packages_failed' => $failed,
        'packages_skipped' => $skipped,
        'packages_private' => $private,
        'items' => $items,
    ]);
}

it('says nothing when everything deposited', function () {
    expect((new DepositReport('vaults '))->problems(reportRun([])))->toBe([]);
});

it('groups problems by reason, collapses noisy groups, and attaches the matching fix', function () {
    $items = [
        ['status' => 'failed', 'reason' => 'credentials_required', 'host' => 'satis.a.test', 'package' => 'a/one', 'version' => 'v1'],
        ['status' => 'failed', 'reason' => 'credentials_required', 'host' => 'satis.a.test', 'package' => 'a/two', 'version' => 'v2'],
        ['status' => 'failed', 'reason' => 'credentials_rejected', 'host' => 'satis.b.test', 'package' => 'b/one', 'version' => 'v1'],
        ['status' => 'failed', 'reason' => 'private_repository', 'package' => 'c/secret', 'version' => 'v1'],
        ['status' => 'failed', 'error' => 'boom: 500', 'package' => 'd/broken', 'version' => 'v1'],
        ['status' => 'skipped', 'reason' => 'private_served_from_team', 'package' => 'e/private', 'version' => 'v1'],
        ['status' => 'skipped', 'reason' => 'missing_dist', 'package' => 'f/nodist', 'version' => 'v1'],
    ];

    for ($index = 0; $index < 12; $index++) {
        $items[] = ['status' => 'skipped', 'reason' => 'path_repository', 'package' => 'local/'.$index, 'version' => 'dev-main'];
    }

    $lines = plain((new DepositReport('composer vaults:'))->problems(reportRun($items, failed: 5, skipped: 14, private: 1)));

    expect($lines)->toContain('Not deposited')
        ->and($lines)->toContain('  ✗ a/one v1  this project needs its own credentials for satis.a.test')
        ->and($lines)->toContain('  ✗ a/two v2  this project needs its own credentials for satis.a.test')
        ->and($lines)->toContain('    → composer vaults:repositories:add satis.a.test (uses the credentials in your auth.json, for this project only)')
        ->and($lines)->toContain('  ✗ b/one v1  credentials for satis.b.test were rejected')
        ->and($lines)->toContain('    → composer vaults:repositories:add satis.b.test (replaces the stored credentials)')
        ->and($lines)->toContain('  ✗ c/secret v1  private repository, not hosted on Vaults yet')
        ->and($lines)->toContain('  ✗ d/broken v1  boom: 500')
        ->and($lines)->toContain('  – 1 private package served from your team repository, not counted against coverage')
        ->and($lines)->toContain('  – f/nodist v1  no dist url in composer.lock')
        ->and($lines)->toContain('  – 12 local path dependencies skipped')
        ->and(implode("\n", $lines))->not->toContain('local/11');
});

it('truncates long groups of the same reason', function () {
    $items = [];

    for ($index = 0; $index < 14; $index++) {
        $items[] = ['status' => 'failed', 'reason' => 'credentials_required', 'host' => 'satis.test', 'package' => 'paid/'.$index, 'version' => 'v1'];
    }

    $lines = plain((new DepositReport('vaults '))->problems(reportRun($items, failed: 14)));

    expect($lines)->toContain('    … and 4 more with the same reason')
        ->and(count(array_filter($lines, fn (string $line): bool => str_starts_with($line, '  ✗ paid/'))))->toBe(10);
});

it('hints at private:link only when private packages were deposited and the repository is missing', function () {
    $run = reportRun([
        ['status' => 'deposited', 'private' => true, 'package' => 'paid/lib', 'version' => 'v1'],
    ]);

    $report = new DepositReport('vaults ');

    expect($report->privateHint($run, true))->toBe([])
        ->and($report->privateHint(reportRun([]), false))->toBe([])
        ->and(plain($report->privateHint($run, false)))->toBe([
            '',
            'paid/lib was deposited as a private package for your team.',
            '→ Run vaults private:link so this project can install them from your private repository.',
        ]);
});

it('aligns detected private repositories into columns and pads long package lists', function () {
    $repositories = [
        ['host' => 'satis.dedoc.co', 'packages' => ['dedoc/scramble-pro'], 'credentials' => ['type' => 'http-basic', 'username' => 'tom', 'secret' => 'x', 'source' => '/app/auth.json']],
        ['host' => 'repo.packagist.com', 'packages' => ['a/b', 'c/d', 'e/f', 'g/h'], 'credentials' => ['type' => 'bearer', 'username' => null, 'secret' => 'x', 'source' => 'COMPOSER_AUTH']],
    ];

    $report = new DepositReport('vaults ');

    expect($report->privateRepositories([]))->toBe([])
        ->and(plain($report->privateRepositories($repositories)))->toBe([
            '',
            'Private repositories in composer.lock',
            '  satis.dedoc.co      1 package  dedoc/scramble-pro',
            '                      credentials in /app/auth.json (http-basic, tom)',
            '  repo.packagist.com  4 packages  a/b, c/d, e/f and 1 more',
            '                      credentials in COMPOSER_AUTH (bearer)',
            '',
            'Vaults can use these credentials to authorise this project for those packages. Other projects are never given access through them.',
        ]);
});

it('shows only the new packages as work when the server says what was already in Vaults', function () {
    $run = DepositRun::fromArray([
        'uuid' => 'run',
        'status' => 'completed',
        'packages_total' => 142,
        'packages_deposited' => 142,
        'packages_already_deposited' => 141,
    ]);

    $report = new DepositReport('vaults ');

    expect($run->packagesToDeposit())->toBe(1)
        ->and($run->packagesProcessed())->toBe(1)
        ->and($run->packagesNewlyDeposited())->toBe(1)
        ->and(plain([$report->scope($run)])[0])->toBe('142 packages in composer.lock · 141 already in Vaults · 1 to deposit')
        ->and(plain([$report->summary($run)])[0])->toBe('Deposited 1 new · Already in Vaults 141 · Skipped 0 · Failed 0');
});

it('says so when nothing new needs depositing', function () {
    $run = DepositRun::fromArray([
        'uuid' => 'run',
        'status' => 'completed',
        'packages_total' => 142,
        'packages_deposited' => 142,
        'packages_already_deposited' => 142,
    ]);

    expect($run->packagesToDeposit())->toBe(0)
        ->and(plain([(new DepositReport('vaults '))->scope($run)])[0])->toBe('✓ All 142 packages in composer.lock are already in Vaults.');
});

it('keeps the original wording for a server that does not report what was already in Vaults', function () {
    $run = DepositRun::fromArray(['uuid' => 'run', 'status' => 'completed', 'packages_total' => 3, 'packages_deposited' => 3]);

    $report = new DepositReport('vaults ');

    expect($run->analysed)->toBeTrue()
        ->and($report->scope($run))->toBeNull()
        ->and(plain([$report->summary($run)])[0])->toBe('Deposited 3 · Skipped 0 · Failed 0');
});

it('knows a run is still being analysed until the server has counted what it already holds', function () {
    $run = DepositRun::fromArray(['uuid' => 'run', 'status' => 'running', 'packages_total' => 142, 'packages_already_deposited' => null]);

    expect($run->analysed)->toBeFalse();
});
