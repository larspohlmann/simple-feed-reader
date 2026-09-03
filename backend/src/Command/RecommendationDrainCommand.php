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
 * The on-demand drainer (#371): a short-lived worker, spawned by a web request on
 * installs with no persistent worker, that drives every active recommendation run
 * to completion at worker concurrency. Each sweep marks
 * RecommendationDriverKind::OnDemandDrainer's liveness key so open browsers demote
 * to the read-only /current poll, then clears it on exit so poll and cron take
 * over immediately instead of waiting out the freshness window. Own key rationale:
 * {@see WorkerPresence}.
 *
 * Only ever advances existing runs -- starting runs (and their spend budget, #308)
 * stays with the callers that already own it.
 */
#[AsCommand(
    name: 'app:recommendations:drain',
    description: 'Advance all active recommendation runs until none is left',
)]
final class RecommendationDrainCommand extends Command
{
    public const string LOCK_NAME = 'recommendation-drain';

    /**
     * What a SIGKILL costs, and nothing else -- the one thing this TTL decides. A
     * hard kill skips `finally` and the shutdown hook, so the key sits until it
     * lapses and no replacement drainer can spawn; 900 s bounds that blackout at
     * fifteen minutes.
     *
     * It does NOT bound one sweep's worst case (ten runs x MAX_ATTEMPTS x provider
     * timeout -- five hours standard, more on the slow profile), even though the
     * key only refreshes between sweeps; since #433 a single call can outlast the
     * TTL alone. That's the same lapse, not a new failure: keepsHoldingTheLock()
     * re-bids for the key mid-sweep and carries on when it wins, so a longer TTL
     * would only multiply the post-SIGKILL blackout for no benefit here.
     *
     * A lapse can let a second drainer in for the rest of the sweep; the incumbent's
     * re-bid then loses and it hands over cleanly. Overlapping drainers can't
     * double-advance a run regardless -- every advance takes
     * RecommendationRunAdvancer's per-user lock, which is also why runs keep
     * progressing under cron no matter who holds this one.
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

        // A fatal error skips finally, and this CLI has no request timeout --
        // same belt-and-braces as RecommendationRunAdvancer::advance(). The
        // release is token-scoped (never frees a lock this process lost) and
        // SIGKILL falls back to the TTL. The flag keeps it a safety net, not a
        // double cleanup: this closure runs on EVERY termination, so without it
        // the ordinary path released the lock twice.
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
     * This process was a worker only as long as it lived. Leaving its liveness key
     * fresh would make the poll driver report the run as still running and stop
     * cron's respawn net, for up to WorkerPresence::FRESH_SECONDS -- eleven minutes
     * of a frozen run on a worker-less install. Unconditional and safe: it names
     * the drainer's own kind, so it cannot touch a persistent worker's heartbeat.
     * Best-effort because this also runs from the shutdown hook, where a throw
     * would pile a second fatal; a failed clear just leaves the key to age out.
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
     * A failed refresh() only proves the key is gone, not that another drainer
     * owns it -- walking away would drop healthy in-flight work back to the
     * once-a-minute cron. So this re-bids and carries on when it wins. Only a
     * lost bid proves a second drainer holds it, and that handoff is as benign
     * as never winning acquire() in the first place -- not a failure worth a
     * non-SUCCESS exit.
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
