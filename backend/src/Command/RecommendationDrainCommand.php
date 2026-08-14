<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Worker\WorkerRunSweep;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * The on-demand drainer (#371): a short-lived worker that drives every
 * active recommendation run to completion at worker concurrency, spawned by
 * a web request on installs that have no persistent worker. Each sweep marks
 * the heartbeat, so open browsers demote to the read-only /current poll
 * while this runs; when it exits and the heartbeat goes stale, the poll and
 * cron paths take over again seamlessly.
 *
 * It only ever advances existing runs -- starting runs (and their spend
 * budget, #308) stays with the callers that already own it.
 */
#[AsCommand(
    name: 'app:recommendations:drain',
    description: 'Advance all active recommendation runs until none is left',
)]
final class RecommendationDrainCommand extends Command
{
    public const string LOCK_NAME = 'recommendation-drain';

    /**
     * Refreshed between sweeps, so it needs to outlive one sweep iteration,
     * not the whole drain. A crashed drainer therefore blocks a respawn for
     * at most this long -- and only the *fast* path: the per-minute cron
     * sweep keeps advancing the runs regardless, because it takes the
     * per-user run locks, never this one.
     */
    public const float LOCK_TTL_SECONDS = 900.0;

    /**
     * A stuck run must never pin a process forever: past the cap the
     * drainer exits and the next cron tick spawns a fresh one, which
     * resumes from the last committed checkpoint.
     */
    public const int MAX_RUNTIME_SECONDS = 3600;

    /**
     * The advancer blocks on provider calls, so the loop is naturally
     * paced; this only keeps the tail -- repeated sweeps over a run that is
     * finishing up -- from spinning hot.
     */
    public const float SWEEP_PAUSE_SECONDS = 1.0;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly WorkerRunSweep $sweep,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'detach',
            null,
            InputOption::VALUE_NONE,
            'Leave the spawning request\'s session (used by the web spawner)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('detach') && \function_exists('posix_setsid')) {
            // Survival does not depend on a setsid/nohup wrapper binary --
            // the production host has neither setsid nor a crontab, but it
            // does have ext-posix (#371 Strato probe). Behind --detach so an
            // in-process test run cannot detach the test runner's session.
            posix_setsid();
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            // Another drainer already owns the work; concurrent spawns
            // (start + cron racing) are expected and harmless by design.
            return Command::SUCCESS;
        }

        // A crash skips finally, and this CLI process has no request
        // timeout watching over it; same belt-and-braces as
        // RecommendationRunAdvancer::advance() -- the release is
        // token-scoped, so it can never free a lock this process no longer
        // owns, and SIGKILL still falls back to the TTL.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Best-effort: a failure to release during shutdown must
                // not raise a second fatal. The TTL still bounds the stall.
            }
        });

        try {
            $this->drainUntilDoneOrCapped($lock);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    private function drainUntilDoneOrCapped(LockInterface $lock): void
    {
        $startedAt = $this->clock->now();

        while ($this->sweep->sweep() > 0) {
            if ($this->clock->now()->getTimestamp() - $startedAt->getTimestamp() >= self::MAX_RUNTIME_SECONDS) {
                return;
            }

            try {
                $lock->refresh();
            } catch (LockExceptionInterface) {
                // A sweep's duration is the SUM over every active run
                // (WorkerRunSweep::sweep()), so a sweep spanning many users
                // can outrun LOCK_TTL_SECONDS between refreshes. Losing the
                // lock here means another drainer already re-acquired it
                // and now owns the work -- the same benign handoff as never
                // winning acquire() above, not a failure worth a non-SUCCESS
                // exit.
                return;
            }

            $this->clock->sleep(self::SWEEP_PAUSE_SECONDS);
        }
    }
}
