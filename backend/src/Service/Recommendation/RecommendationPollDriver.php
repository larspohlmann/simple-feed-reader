<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Worker\WorkerPresence;
use Psr\Log\LoggerInterface;

/**
 * The poll driver's side of #311's arbitration: while somebody else drives the runs (the
 * persistent worker, or an on-demand drainer #371), that driver owns execution and a poll
 * tick becomes a pure status read; with nobody driving, the #308 poll behaviour applies
 * untouched. Kill the driver mid-run and the next poll tick advances from the checkpoint --
 * automatic in both directions, no config switch.
 *
 * The heartbeat is a hint, the per-user lock is the truth: a busy advance is answered the
 * same way a fresh heartbeat is (pending or running, background true, client keeps
 * watching), since both mean somebody else owns execution. Since #439 they are not
 * answered identically: a busy result reached only because the presence check found
 * nobody driving means the lock is held with no heartbeat behind it, and that case alone
 * also carries waitingForLock, so the client can tell "a worker owns this" from "nothing
 * is claiming to move this". That case alone is worth a warning -- the advancer's own
 * failed acquire stays silent, since down there a held lock is ordinary.
 *
 * The flag says that much and no more, not that the holder is dead. Two known false
 * positives are left, both healthy:
 *
 * - A second tab of the same account: a poll tick is deliberately not a driver kind
 *   (#433), so two tabs alternate and the loser reports a lock nobody has vouched for.
 * - Two cron passes at once: /maintenance/tick takes no lock over its sweep half, so
 *   overlapping passes drive under one CronSweep key; the first to finish surrenders it
 *   while the other still drives, and a poll landing before the survivor's next mark
 *   finds its lock held with nothing behind it.
 *
 * A live holder is distinguishable from a dead one only by a second liveness subsystem,
 * not worth building for two spurious warnings; the flag and log line are worded so
 * neither lies.
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

        // Busy means the per-user lock is held: another worker, tab, or CLI
        // run is advancing this run. Reporting an error stopped the client
        // polling a healthy run (#311). Here the presence check already said
        // "nobody driving", so waitingForLock names that gap rather than
        // letting the client read it as a healthy background run (#439).
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
     * Logged every time, no debounce: a lock held by a driver that says it is alive never
     * reaches here (the presence check answers that first), so what remains is one of the
     * two healthy races above, or a gone holder. #439 was diagnosed from the last going
     * unrecorded. The line stops at what is known, since the races are indistinguishable
     * here; the lock name is the operator's handle -- the row to inspect, and to delete
     * once its holder is provably dead.
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
