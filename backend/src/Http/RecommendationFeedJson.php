<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\RecommendationFeedRow;
use App\Service\Recommendation\Feed\Model\FeedAnnotationVisibilityModel;
use App\Service\Recommendation\Feed\Model\ForYouFeedPageModel;

final readonly class RecommendationFeedJson
{
    public function __construct(
        private EntryJson $entryJson,
    ) {
    }

    /**
     * A page of the for-you feed. `runId` and `runGeneratedAt` ride on every entry for the run divider; the reason and
     * the score only when the reader turned on "show score and reasons", and always together
     * (FeedAnnotationVisibilityModel).
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function page(ForYouFeedPageModel $page): array
    {
        return [
            'entries' => $this->entries($page->rows, $page->visibility),
            'nextCursor' => $page->nextCursor,
        ];
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $rows, FeedAnnotationVisibilityModel $visibility): array
    {
        return array_map(function (RecommendationFeedRow $row) use ($visibility): array {
            // ATOM, like the run report's forYou.generatedAt: the client compares them to find the newest run's picks.
            $entry = $this->entryJson->listRow($row->row) + [
                'runId' => $row->runId,
                'runGeneratedAt' => $row->runGeneratedAt?->format(\DateTimeInterface::ATOM),
            ];
            if (!$visibility->showScoreAndReasons) {
                return $entry;
            }

            return $entry + [
                'recommendationReason' => $row->reason,
                'recommendationScore' => $row->score,
            ];
        }, $rows);
    }
}
