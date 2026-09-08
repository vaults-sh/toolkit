<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vaults\ComposerPlugin\Support\VaultsCommand;
use Vaults\Diagnostics\Doctor;

final class DoctorCommand extends VaultsCommand
{
    protected function configure(): void
    {
        $this->setName('vaults:doctor')->setDescription('Diagnose connectivity to the Vaults API and edge infrastructure');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $token = $store->token();
        $client = $token === null ? $this->client() : $this->client()->withToken($token);

        $report = (new Doctor($client, $store, $this->probe(), 'run composer vaults:login'))->run($this->directory());

        (new Table($output))->setHeaders(['Check', 'Result'])->setRows($report->rows)->render();

        if (! $report->healthy) {
            $output->writeln('<comment>Some checks failed.</comment>');

            return self::FAILURE;
        }

        $output->writeln('<info>Everything looks healthy.</info>');

        return self::SUCCESS;
    }
}
