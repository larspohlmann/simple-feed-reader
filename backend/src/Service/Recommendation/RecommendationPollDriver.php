<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Worker\WorkerPresence;

/**
 * The poll driver's side of #311's arbitration: a fresh worker heartbeat
 * means the worker owns execution, so a poll tick becomes a pure status
 * read; a stale one means the #308 poll behaviour applies untouched. Kill
 * the worker mid-run and the next poll tick advances from the checkpoint --
 * the fallback is automatic in both directions, with no config switch.
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
        if ($this->presence->isRecommendationWorkerAlive()) {
            return $this->current($user);
        }

        return $this->advancer->advance($user);
    }

    public function current(User $user): RecommendationRunReport
    {
        $latest = $this->runs->findLatestForUser($user);
        $report = null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);

        return $this->presence->isRecommendationWorkerAlive() ? $report->inBackground() : $report;
    }
}
