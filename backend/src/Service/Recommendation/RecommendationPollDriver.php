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
 * comes back busy is answered the same way a fresh heartbeat is, because
 * both mean the same thing -- somebody else owns execution right now.
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
        return RecommendationRunReport::STATUS_BUSY === $report->status
            ? $this->latestReport($user)->inBackground()
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
