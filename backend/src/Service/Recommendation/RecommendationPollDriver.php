<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Worker\WorkerPresence;
use Psr\Log\LoggerInterface;

/**
 * The poll driver's side of #311's arbitration: while somebody else drives the runs
 * (the persistent worker, or an on-demand drainer #371), that driver owns execution
 * and a poll tick becomes a pure status read; with nobody driving, the #308 poll
 * behaviour applies untouched. Kill the driver mid-run and the next poll tick advances
 * from the checkpoint — the fallback is automatic in both directions, no config switch.
 *
 * The heartbeat is a hint, the per-user lock is the truth: an advance that comes back
 * busy is answered the same way a fresh heartbeat is (pending or running, background
 * true, the client keeps watching), because both mean somebody else owns execution.
 * They are not answered identically any more (#439): a busy result reached only because
 * the presence check found nobody driving means the lock is held with no driver
 * heartbeat behind it, and that case alone also carries waitingForLock, so the client
 * can tell "a worker owns this" from "nothing is claiming to move this". That case is
 * the one worth a warning, and the reason the advancer's own failed acquire stays
 * silent: down there a held lock is ordinary.
 *
 * The flag says that much and no more — not that the holder is dead. Every regime that
 * drives somebody else's runs marks liveness, so two known false positives are left,
 * both healthy:
 *
 * - A second tab of the same account. A poll tick is deliberately not a driver kind
 *   (#433): it advances the run of the account watching it, so two tabs of one account
 *   alternate and the loser reports a lock nobody has vouched for.
 * - Two cron passes at once. /maintenance/tick takes no lock over its sweep half, so
 *   overlapping passes drive under the one CronSweep key, and the first to finish
 *   surrenders it while the other is still driving. Until the survivor's next mark
 *   (between runs, or a chunk mid-call) writes the key back, a poll landing in that gap
 *   finds its lock held with nothing behind it.
 *
 * A live holder is distinguishable from a dead one only by a second liveness subsystem,
 * and neither spurious warning is worth that; the flag and the log line are worded so
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
     * Logged every time, no debounce: a lock held by a driver that says it is alive
     * never reaches here (the presence check answers that first), so what is left is
     * one of the two healthy races the class doc enumerates, or a gone holder. #439 was
     * diagnosed from the last going unrecorded. The line stops at what is known, since
     * the races are indistinguishable here. The lock name is the operator's handle: the
     * row to inspect, and to delete once its holder is provably dead.
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
