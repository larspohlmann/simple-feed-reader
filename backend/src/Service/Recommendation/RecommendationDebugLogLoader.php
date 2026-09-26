<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;

/**
 * What the debug panel shows: the runs it may switch between, and the rows of the one it is looking at.
 * One run at a time on purpose: the panel polls every two seconds mid-run, and shipping all ten retained
 * runs' rows on every poll costs ten times as much for nine runs nobody is reading.
 */
final readonly class RecommendationDebugLogLoader
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private RecommendationRunRepository $runs,
    ) {
    }

    /**
     * @param int $requestedRunId any id outside the retention window selects
     *                            the newest run instead — including the 0 an
     *                            absent query parameter reads as, and a
     *                            selection the window has since dropped. A
     *                            stale pick lands on something real rather
     *                            than on an empty panel
     */
    public function forUser(User $user, int $requestedRunId): RecommendationDebugLog
    {
        $runs = $this->runs->findNewestForUser($user, RunLogRetention::RUNS);
        $selected = self::select($runs, $requestedRunId);

        if (null === $selected) {
            return RecommendationDebugLog::empty();
        }

        $selectedId = $selected->requireId();

        return new RecommendationDebugLog(
            $this->logs->listForRun($user, $selectedId),
            $this->logs->streamingTextForRun($user, $selectedId),
            $selected,
            $runs,
        );
    }

    /** @param list<RecommendationRun> $runs newest first */
    private static function select(array $runs, int $requestedRunId): ?RecommendationRun
    {
        foreach ($runs as $run) {
            if ($run->getId() === $requestedRunId) {
                return $run;
            }
        }

        return $runs[0] ?? null;
    }
}
