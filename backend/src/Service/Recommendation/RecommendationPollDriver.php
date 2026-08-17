<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Worker\WorkerPresence;
use Psr\Log\LoggerInterface;

/**
 * The poll driver's side of #311's arbitration: while somebody else drives
 * the runs -- the persistent worker, or an on-demand drainer (#371) -- that
 * driver owns execution, so a poll tick becomes a pure status read; with
 * nobody driving, the #308 poll behaviour applies untouched. Kill the driver
 * mid-run and the next poll tick advances from the checkpoint -- the fallback
 * is automatic in both directions, with no config switch.
 *
 * The heartbeat is a hint, the per-user lock is the truth: an advance that
 * comes back busy is answered the same way a fresh heartbeat is -- pending or
 * running, background true, the client keeps watching -- because both mean
 * somebody else owns execution right now. They are not answered identically
 * any more, though (#439): a busy result reached only because the presence
 * check above already found nobody driving means the lock is held with no
 * driver heartbeat behind it, and that case alone also carries
 * waitingForLock, so the client can tell "a worker owns this" from "nothing
 * is claiming to move this". That same case is the one worth a warning in the
 * log, and the reason the advancer's own failed acquire stays silent: down
 * there a held lock is ordinary.
 *
 * The flag says exactly that much and no more -- not that the holder is dead.
 * Every regime that drives somebody else's runs marks liveness, so two known
 * false positives are left, both of them healthy:
 *
 * - A second tab of the same account. A poll tick is deliberately not a driver
 *   kind (#433): it advances the run of the account watching it, so two tabs of
 *   one account alternate and the loser reports a lock nobody has vouched for.
 * - Two cron passes at once. /maintenance/tick takes no lock over its sweep
 *   half, so overlapping passes drive under the one CronSweep key, and the
 *   first to finish surrenders it while the other is still driving. Until the
 *   survivor's next mark -- between runs, or a chunk arriving mid-call -- writes
 *   the key back, a poll that lands in that gap finds its lock held with
 *   nothing behind it.
 *
 * A live holder is distinguishable from a dead one only by a second liveness
 * subsystem, and neither spurious warning is worth that; the flag and the log
 * line are worded so neither lies when it happens.
 */
final readonly class RecommendationPollDriver
{
    public function __construct(
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
        private WorkerPresence $presence,
        private LoggerInterface $logger,
    ) {
    }

    public function poll(User $user): RecommendationRunReport
    {
        if ($this->presence->isAnybodyDrivingRecommendationRuns()) {
            return $this->latestReport($user)->inBackground();
        }

        $report = $this->advancer->advance($user, TickDriver::Poll);

        // Busy is not a failure and never was: it means the per-user lock is
        // held, so somebody else -- a worker whose heartbeat has not landed
        // yet, another tab, a CLI run -- is advancing this very run right
        // now. Answering with an error made the client stop polling a
        // healthy run (#311 final review, Critical 2). The honest answer is
        // where the run actually stands, flagged as somebody else's work so
        // the client keeps watching instead of driving.
        //
        // The presence check above already came back "nobody driving" or
        // this line would never run, so a busy report here is the one case
        // where the lock is held and no driver kind has vouched for it
        // (#439). waitingForLock names that gap for the client instead of
        // leaving it to read the same as a healthy background run.
        if (RecommendationRunReport::STATUS_BUSY !== $report->status) {
            return $report;
        }

        $this->logLockWithNoHeartbeatBehindIt($user);

        return $this->latestReport($user)->inBackground()->waitingForLock();
    }

    public function current(User $user): RecommendationRunReport
    {
        $report = $this->latestReport($user);

        return $this->presence->isAnybodyDrivingRecommendationRuns() ? $report->inBackground() : $report;
    }

    /**
     * Logged every time, with no debounce: a lock held by a driver that says
     * it is alive never reaches here -- the presence check answers that case
     * first -- so what is left is one of the two healthy races the class doc
     * enumerates, or a holder that is gone. #439 was diagnosed from the last
     * going unrecorded. The line stops at what is known, because the races are
     * indistinguishable from it here.
     *
     * The lock name is the operator's handle on it: it is the row to inspect,
     * and the one to delete once its holder is provably dead.
     */
    private function logLockWithNoHeartbeatBehindIt(User $user): void
    {
        $this->logger->warning('Recommendation run lock is held with no driver heartbeat behind it', [
            'lock' => RecommendationRunAdvancer::lockNameFor($user),
        ]);
    }

    private function latestReport(User $user): RecommendationRunReport
    {
        $latest = $this->runs->findLatestForUser($user);

        return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
    }
}
