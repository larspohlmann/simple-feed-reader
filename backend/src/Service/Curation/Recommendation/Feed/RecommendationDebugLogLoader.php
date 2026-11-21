<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed;

use App\Entity\User;
use App\Enum\AiRunKind;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\StoryAiRunRepository;
use App\Service\Curation\Recommendation\Feed\Model\DebugLogRunModel;
use App\Service\Curation\Recommendation\Feed\Model\DebugLogRunRequestModel;
use App\Service\Curation\Recommendation\Feed\Model\RecommendationDebugLogModel;
use App\Service\Curation\Recommendation\Run\Support\RunLogRetention;

/**
 * What the debug panel shows: the runs it may switch between, and the rows of the one it is looking at.
 * One run at a time on purpose: the panel polls every two seconds mid-run, and shipping every retained
 * run's rows on every poll costs many times as much for runs nobody is reading.
 */
final readonly class RecommendationDebugLogLoader
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private RecommendationRunRepository $runs,
        private ProfileRunRepository $profileRuns,
        private StoryAiRunRepository $storyAiRuns,
    ) {
    }

    /** A request outside the retention window, or none, selects the newest For you run, else the newest run. */
    public function forUser(User $user, ?DebugLogRunRequestModel $requested): RecommendationDebugLogModel
    {
        $runs = $this->collectRetainedRuns($user);
        $selected = self::select($runs, $requested);

        if (null === $selected) {
            return RecommendationDebugLogModel::empty();
        }

        return new RecommendationDebugLogModel(
            $this->logs->listForRun($user, $selected->kind, $selected->id),
            $this->logs->streamingTextForRun($user, $selected->kind, $selected->id),
            $selected,
            $runs,
        );
    }

    /** @return list<DebugLogRunModel> newest first */
    private function collectRetainedRuns(User $user): array
    {
        $runs = [
            ...array_map(
                DebugLogRunModel::fromRecommendationRun(...),
                $this->runs->findNewestForUser($user, RunLogRetention::RUNS),
            ),
            ...array_map(
                DebugLogRunModel::fromProfileRun(...),
                $this->profileRuns->findNewestForUser($user, RunLogRetention::RUNS),
            ),
            ...array_map(
                DebugLogRunModel::fromStoryAiRun(...),
                $this->storyAiRuns->findNewestForUser($user, RunLogRetention::RUNS),
            ),
        ];

        usort(
            $runs,
            static fn (DebugLogRunModel $left, DebugLogRunModel $right): int
                => [$right->createdAt, $right->kind->tieRank(), $right->id]
                <=> [$left->createdAt, $left->kind->tieRank(), $left->id],
        );

        return $runs;
    }

    /** @param list<DebugLogRunModel> $runs newest first */
    private static function select(array $runs, ?DebugLogRunRequestModel $requested): ?DebugLogRunModel
    {
        $newestForYouRun = null;
        foreach ($runs as $run) {
            if (true === $requested?->isFor($run)) {
                return $run;
            }
            if (AiRunKind::ForYou === $run->kind) {
                $newestForYouRun ??= $run;
            }
        }

        return $newestForYouRun ?? $runs[0] ?? null;
    }
}
