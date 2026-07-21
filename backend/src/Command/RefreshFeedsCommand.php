<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SSH-invoked manual and deploy-time refresh. Unattended refreshes go through
 * the maintenance endpoint instead: the production host has no crontab.
 */
#[AsCommand(name: 'app:feeds:refresh', description: 'Fetch due feeds and ingest new entries')]
final class RefreshFeedsCommand extends Command
{
    /**
     * Comfortably inside the host's 240 s max_execution_time, leaving room for
     * the runner's own safety margin and the pruning pass.
     */
    private const DEFAULT_BUDGET_SECONDS = 180;

    public function __construct(private readonly RefreshRunner $refreshRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'budget',
                null,
                InputOption::VALUE_REQUIRED,
                'Time budget in seconds',
                (string) self::DEFAULT_BUDGET_SECONDS,
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the schedule (5-minute cooldown still applies)')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Refresh a single feed by id')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Refresh only feeds this user id subscribes to')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Skip retention pruning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $budget = $this->intOption($input, 'budget');
        if ($budget === null || $budget < 1) {
            $io->error('The --budget option must be a positive integer number of seconds.');

            return Command::INVALID;
        }

        $feedId = $this->intOption($input, 'feed');
        $userId = $this->intOption($input, 'user');

        $request = match (true) {
            $feedId !== null => RefreshRequest::forFeed($feedId, $budget),
            $userId !== null => RefreshRequest::forUser($userId, $budget),
            default => RefreshRequest::allDue(
                $budget,
                prune: !(bool) $input->getOption('no-prune'),
                force: (bool) $input->getOption('force'),
            ),
        };

        $report = $this->refreshRunner->run($request);

        if ($report->status === 'busy') {
            $io->warning('Another refresh run is already in progress.');

            return Command::SUCCESS;
        }

        foreach ($report->toArray() as $key => $value) {
            $io->writeln(sprintf('%-18s %s', $key, (string) $value));
        }

        if ($report->status === 'aborted') {
            $io->error('The run aborted early: persistence failed. Unprocessed feeds remain due.');

            return Command::FAILURE;
        }

        if ($report->status === 'partial') {
            $io->note(sprintf('%d feed(s) still due — run again to continue.', $report->remaining));
        }

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
