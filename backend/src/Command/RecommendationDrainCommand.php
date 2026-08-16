<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Worker\RecommendationDriverKind;
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
 * RecommendationDriverKind::OnDemandDrainer's liveness key, so open browsers
 * demote to the read-only /current poll while this runs; on the way out it
 * clears that key again, so the poll and cron paths take over immediately
 * rather than after the freshness window has aged out a worker that no longer
 * exists. Why the drainer has a key of its own is written out on
 * {@see WorkerPresence}.
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
     * What a SIGKILL costs, and nothing else -- this is the one thing the TTL
     * still decides. A hard kill skips both the `finally` and the shutdown
     * hook, so the key sits there until it lapses, and no replacement drainer
     * can be spawned in the meantime; 900 s bounds that blackout at fifteen
     * minutes.
     *
     * It deliberately does NOT bound one sweep's worst case (ten runs x
     * MAX_ATTEMPTS x the provider timeout, i.e. five hours on the standard
     * profile and far more on the slow one), even though the key is only
     * refreshed between sweeps. Since #433 a single call on a slow connection
     * can outlast this TTL by itself. That is the same lapse, not a new
     * failure mode: a lapse mid-sweep does not end the drain, because
     * keepsHoldingTheLock() bids for the key again and carries on when the bid
     * wins, so the long TTL that would prevent it buys nothing while
     * multiplying the post-SIGKILL blackout many times over.
     *
     * A lapse can let a second drainer in for the rest of the sweep in
     * flight, after which the incumbent's re-bid loses and it hands over
     * cleanly. Overlapping drainers cannot double-advance a run anyway: every
     * advance takes RecommendationRunAdvancer's per-user lock, which is also
     * why runs keep progressing under the cron path no matter who holds this
     * one.
     */
    public const float LOCK_TTL_SECONDS = 900.0;

    /**
     * Bounds the drain *loop*, not the process: the cap is read between
     * sweeps, so the sweep in flight when it passes still runs to its own
     * end. Past the cap the drainer starts no further sweep and exits,
     * surrendering both the lock and its liveness key, and the next cron tick
     * spawns a fresh one that resumes from the last committed checkpoint.
     */
    public const int MAX_RUNTIME_SECONDS = 3600;

    /**
     * The advancer blocks on provider calls, so the loop is naturally
     * paced; this only keeps the tail -- repeated sweeps over a run that is
     * finishing up -- from spinning hot.
     */
    public const float SWEEP_PAUSE_SECONDS = 1.0;

    /**
     * Set by the `finally` that does the ordinary cleanup, read by the
     * shutdown hook that exists only for the crash that skips it. Command
     * state rather than a captured local, so the hook's check reads the value
     * at shutdown time and not the one it closed over.
     */
    private bool $cleanedUp = false;

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

        // A fatal error skips finally, and this CLI process has no request
        // timeout watching over it; same belt-and-braces as
        // RecommendationRunAdvancer::advance() -- the release is
        // token-scoped, so it can never free a lock this process no longer
        // owns, and SIGKILL still falls back to the TTL. The flag is what
        // keeps it a safety net rather than a second cleanup: this closure
        // runs on EVERY termination, so without it the ordinary path
        // released the lock and surrendered the key twice.
        $this->cleanedUp = false;
        register_shutdown_function(function () use ($lock): void {
            if ($this->cleanedUp) {
                return;
            }

            try {
                $lock->release();
            } catch (\Throwable) {
                // Best-effort: a failure to release during shutdown must
                // not raise a second fatal. The TTL still bounds the stall.
            }

            $this->surrenderTheDrainerLiveness();
        });

        try {
            $this->drainUntilDoneOrCapped($lock);
        } finally {
            $lock->release();
            $this->surrenderTheDrainerLiveness();
            $this->cleanedUp = true;
        }

        return Command::SUCCESS;
    }

    /**
     * This process was a worker only for as long as it lived. Leaving its
     * liveness key fresh behind it makes the poll driver report the run as
     * running in the background, and stops the cron's respawn net from
     * bringing a replacement up, for up to WorkerPresence::FRESH_SECONDS --
     * eleven minutes of a frozen run on a worker-less install. Unconditional,
     * and safe to be so: it names the drainer's own kind, so it cannot touch a
     * persistent worker's heartbeat even when both run at once. Best-effort,
     * because this also runs from the shutdown hook, where a throw would pile
     * a second fatal on whatever ended the process; a clear that fails simply
     * leaves the old behavior, a key that ages out.
     */
    private function surrenderTheDrainerLiveness(): void
    {
        try {
            $this->presence->forget(RecommendationDriverKind::OnDemandDrainer);
        } catch (\Throwable) {
            // Deliberately silent: see this method's doc comment.
        }
    }

    private function drainUntilDoneOrCapped(LockInterface $lock): void
    {
        $startedAt = $this->clock->now();

        while ($this->sweep->sweep(RecommendationDriverKind::OnDemandDrainer) > 0) {
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
