<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;

/**
 * Assembles one payload for the debug panel: the runs it may switch between,
 * and the rows of the one it is looking at.
 *
 * The panel reads one run at a time on purpose. The log keeps the last ten
 * runs since #401, and the panel polls every two seconds while a run is in
 * flight — shipping all ten runs' rows on every one of those polls would cost
 * ten times what the panel costs today, for nine runs the user is not reading.
 * The retained runs are named in the payload instead, and the panel asks for
 * the one it wants.
 */
final readonly class RecommendationDebugLogView
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
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, int $requestedRunId): array
    {
        $runs = $this->runs->findNewestForUser($user, RunLogRetention::RUNS);
        $selected = self::select($runs, $requestedRunId);

        if (null === $selected) {
            return RecommendationDebugLogJson::list([], [], null, []);
        }

        $selectedId = $selected->getId() ?? 0;

        return RecommendationDebugLogJson::list(
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
