<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\WorkerPresence;
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
 * A short-lived worker a web request spawns on installs without one: it drives every active run to completion and
 * marks OnDemandDrainer's liveness key meanwhile. It only advances runs; starting one stays with their callers.
 */
#[AsCommand(
    name: 'app:recommendations:drain',
    description: 'Advance all active recommendation runs until none is left',
)]
final class RecommendationDrainCommand extends Command
{
    public const string LOCK_NAME = 'recommendation-drain';

    /**
     * What a SIGKILL costs: the key outlives a killed drainer by this long. It need not cover a sweep, because
     * refreshOrReacquireLock() re-bids after a lapse and every advance also takes the per-user run lock.
     */
    public const float LOCK_TTL_SECONDS = 900.0;

    /** Read between sweeps: past it the drainer starts no new sweep and exits, and the next cron tick respawns one. */
    public const int MAX_RUNTIME_SECONDS = 3600;

    /**
     * The advancer blocks on provider calls, so the loop is naturally
     * paced; this only keeps the tail -- repeated sweeps over a run that is
     * finishing up -- from spinning hot.
     */
    public const float SWEEP_PAUSE_SECONDS = 1.0;

    /** Set by the `finally`; a property, not a captured local, so the shutdown hook reads its value at shutdown. */
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
            // The production host has no setsid binary but has ext-posix (#371's probe). Behind --detach so an
            // in-process test run cannot detach the test runner's session.
            posix_setsid();
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            // Another drainer already owns the work; concurrent spawns
            // (start + cron racing) are expected and harmless by design.
            return Command::SUCCESS;
        }

        // A fatal error skips `finally`, so a shutdown hook releases too (token-scoped; SIGKILL falls back to the
        // TTL). The flag stops it releasing again after the ordinary path already did.
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
     * A fresh drainer key after exit would freeze the run for up to WorkerPresence::FRESH_SECONDS on a worker-less
     * install. It names only the drainer's kind, and never throws: the shutdown hook calls it too.
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

            if (!$this->refreshOrReacquireLock($lock)) {
                return;
            }

            $this->clock->sleep(self::SWEEP_PAUSE_SECONDS);
        }
    }

    /**
     * A failed refresh() proves only that the key lapsed, so this re-bids and carries on when it wins. A lost bid means
     * another drainer took over: a clean handoff, not a failure.
     */
    private function refreshOrReacquireLock(LockInterface $lock): bool
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
