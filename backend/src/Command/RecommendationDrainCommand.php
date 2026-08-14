<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\OpenAiCompatibleChatClient;
use App\Service\Worker\WorkerPresence;
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
 * while this runs; on the way out it clears that heartbeat again, so the poll
 * and cron paths take over immediately rather than after the freshness window
 * has aged out a worker that no longer exists.
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
     * Refreshed between sweeps, so it must outlive one whole sweep, not the
     * whole drain. A sweep's duration is the SUM over the runs it touches:
     * RecommendationRunRepository::MAXIMUM_RUNS_PER_SWEEP runs, each of which
     * can spend RecommendationRun::MAX_ATTEMPTS in-tick retry rounds bounded
     * by OpenAiCompatibleChatClient::TIMEOUT_SECONDS -- the same provider
     * ceiling RecommendationRunAdvancer's per-user lock and
     * WorkerPresence::FRESH_SECONDS are both derived from. Derived from those
     * published constants, plus a margin for the surrounding bookkeeping,
     * rather than a bare number: the earlier flat 900 s was shorter than one
     * busy sweep, so the key could lapse mid-sweep while this process was
     * still working.
     *
     * The cost is that a drainer killed hard -- SIGKILL skips both the
     * `finally` and the shutdown hook -- parks the key for the full TTL. That
     * blocks only a second drainer: the per-minute cron sweep keeps advancing
     * the runs regardless, because it takes the per-user run locks, never
     * this one.
     */
    public const float LOCK_TTL_SECONDS = RecommendationRunRepository::MAXIMUM_RUNS_PER_SWEEP
        * RecommendationRun::MAX_ATTEMPTS * OpenAiCompatibleChatClient::TIMEOUT_SECONDS
        + 300.0;

    /**
     * Bounds the drain *loop*, not the process: the cap is read between
     * sweeps, so the sweep in flight when it passes still runs to its own
     * end. Past the cap the drainer starts no further sweep and exits,
     * surrendering both the lock and the heartbeat, and the next cron tick
     * spawns a fresh one that resumes from the last committed checkpoint.
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
        private readonly WorkerPresence $presence,
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
        register_shutdown_function(function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Best-effort: a failure to release during shutdown must
                // not raise a second fatal. The TTL still bounds the stall.
            }

            $this->surrenderTheWorkerHeartbeat();
        });

        try {
            $this->drainUntilDoneOrCapped($lock);
        } finally {
            $lock->release();
            $this->surrenderTheWorkerHeartbeat();
        }

        return Command::SUCCESS;
    }

    /**
     * This process was the worker only for as long as it lived. Leaving the
     * heartbeat fresh behind it makes the poll driver report the run as
     * running in the background, and stops the cron's respawn net from
     * bringing a replacement up, for up to WorkerPresence::FRESH_SECONDS --
     * eleven minutes of a frozen run on a worker-less install. On a Docker
     * install the real worker re-marks within ten seconds, so clearing costs
     * nothing there. Best-effort like the lock release, because this also
     * runs from the shutdown hook, where a throw would pile a second fatal on
     * whatever ended the process; a clear that fails simply leaves the old
     * behavior, a heartbeat that ages out.
     */
    private function surrenderTheWorkerHeartbeat(): void
    {
        try {
            $this->presence->forgetRecommendationSweep();
        } catch (\Throwable) {
            // Deliberately silent: see this method's doc comment.
        }
    }

    private function drainUntilDoneOrCapped(LockInterface $lock): void
    {
        $startedAt = $this->clock->now();

        while ($this->sweep->sweep() > 0) {
            if ($this->clock->now()->getTimestamp() - $startedAt->getTimestamp() >= self::MAX_RUNTIME_SECONDS) {
                return;
            }

            if (!$this->keepsHoldingTheLock($lock)) {
                return;
            }

            $this->clock->sleep(self::SWEEP_PAUSE_SECONDS);
        }
    }

    /**
     * A failed refresh() only proves the key is gone, which is not the same
     * as another drainer owning it: nothing has been handed over, and walking
     * away drops healthy in-flight work back to the once-a-minute cron. So
     * this bids for the key again and carries on when the bid wins. Only a
     * lost bid proves a second drainer really does hold it, and that handoff
     * is as benign as never winning acquire() in the first place -- not a
     * failure worth a non-SUCCESS exit.
     */
    private function keepsHoldingTheLock(LockInterface $lock): bool
    {
        try {
            $lock->refresh();

            return true;
        } catch (LockExceptionInterface) {
            // Fall through to the bid below.
        }

        try {
            return $lock->acquire();
        } catch (LockExceptionInterface) {
            // The store itself refused the bid, so this process can no longer
            // prove it owns the drain. Stopping is the safe reading, and the
            // cron path still carries the runs.
            return false;
        }
    }
}
