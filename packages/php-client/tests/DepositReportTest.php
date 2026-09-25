<?php

declare(strict_types=1);

use Vaults\Report\DepositReport;
use Vaults\Result\DepositRun;

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

    $lines = (new DepositReport('composer vaults:'))->problems(reportRun($items, failed: 5, skipped: 14, private: 1));

    expect($lines)->toContain('Not deposited:')
        ->and($lines)->toContain('  a/one v1  needs credentials for satis.a.test')
        ->and($lines)->toContain('  a/two v2  needs credentials for satis.a.test')
        ->and($lines)->toContain('    Give Vaults the credentials from auth.json: composer vaults:repositories:add satis.a.test')
        ->and($lines)->toContain('  b/one v1  credentials for satis.b.test were rejected')
        ->and($lines)->toContain('    Update them with: composer vaults:repositories:add satis.b.test')
        ->and($lines)->toContain('  c/secret v1  private repository, not hosted on Vaults yet')
        ->and($lines)->toContain('  d/broken v1  boom: 500')
        ->and($lines)->toContain('  1 private package served from your team repository, not counted against coverage.')
        ->and($lines)->toContain('  f/nodist v1  no dist url in composer.lock')
        ->and($lines)->toContain('  12 local path dependencies skipped.')
        ->and(implode("\n", $lines))->not->toContain('local/11');
});

it('truncates long groups of the same reason', function () {
    $items = [];

    for ($index = 0; $index < 14; $index++) {
        $items[] = ['status' => 'failed', 'reason' => 'credentials_required', 'host' => 'satis.test', 'package' => 'paid/'.$index, 'version' => 'v1'];
    }

    $lines = (new DepositReport('vaults '))->problems(reportRun($items, failed: 14));

    expect($lines)->toContain('  … and 4 more with the same reason')
        ->and(count(array_filter($lines, fn (string $line): bool => str_starts_with($line, '  paid/'))))->toBe(10);
});

it('hints at private:link only when private packages were deposited and the repository is missing', function () {
    $run = reportRun([
        ['status' => 'deposited', 'private' => true, 'package' => 'paid/lib', 'version' => 'v1'],
    ]);

    $report = new DepositReport('vaults ');

    expect($report->privateHint($run, true))->toBe([])
        ->and($report->privateHint(reportRun([]), false))->toBe([])
        ->and($report->privateHint($run, false))->toBe([
            '',
            'paid/lib was deposited as a private package for your team.',
            'Run vaults private:link so this project can install them from your private repository.',
        ]);
});
