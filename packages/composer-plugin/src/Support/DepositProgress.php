<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Support;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\Result\DepositRun;

final class DepositProgress
{
    private ?ProgressBar $bar = null;

    private ?string $phase = null;

    public function __construct(private readonly OutputInterface $output) {}

    public function checking(DepositRun $run): void
    {
        $this->advance(
            'checking',
            'Checking '.$run->packagesTotal.' '.($run->packagesTotal === 1 ? 'package' : 'packages').' against Vaults',
            $run->packagesTotal,
            $run->packagesChecked(),
        );
    }

    public function depositing(DepositRun $run): void
    {
        $total = $run->packagesToDeposit();

        $this->advance(
            'depositing',
            'Depositing '.$total.' '.($run->packagesAlreadyDeposited === null ? '' : 'new ').($total === 1 ? 'package' : 'packages'),
            $total,
            $run->packagesProcessed(),
        );
    }

    public function finish(): void
    {
        if ($this->bar !== null) {
            $this->bar->finish();
            $this->output->writeln('');
        }

        $this->bar = null;
        $this->phase = null;
    }

    private function advance(string $phase, string $label, int $total, int $done): void
    {
        if ($this->phase !== $phase) {
            $this->finish();
            $this->phase = $phase;

            if (! $this->output->isDecorated()) {
                $this->output->writeln($label.'...');

                return;
            }

            $this->bar = new ProgressBar($this->output, max(1, $total));
            $this->bar->setFormat('%message% [%bar%] %current%/%max%');
            $this->bar->setMessage($label);
            $this->bar->minSecondsBetweenRedraws(0);
            $this->bar->start();
        }

        $this->bar?->setProgress(max(0, min($total, $done)));
    }
}
