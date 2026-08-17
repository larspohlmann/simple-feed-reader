<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Worker\WorkerPresence;

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
 * check above already found nobody driving means the lock and the heartbeat
 * disagree, and that case alone also carries waitingForLock, so the client
 * can tell "a worker owns this" from "this may be stuck".
 */
final readonly class RecommendationPollDriver
{
    public function __construct(
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
        private WorkerPresence $presence,
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
        // where the lock and the heartbeat disagree: something holds it that
        // is not answering to any known driver kind (#439). waitingForLock
        // names that gap for the client instead of leaving it to read the
        // same as a healthy background run.
        return RecommendationRunReport::STATUS_BUSY === $report->status
            ? $this->latestReport($user)->inBackground()->waitingForLock()
            : $report;
    }

    public function current(User $user): RecommendationRunReport
    {
        $report = $this->latestReport($user);

        return $this->presence->isAnybodyDrivingRecommendationRuns() ? $report->inBackground() : $report;
    }

    private function latestReport(User $user): RecommendationRunReport
    {
        $latest = $this->runs->findLatestForUser($user);

        return null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);
    }
}
