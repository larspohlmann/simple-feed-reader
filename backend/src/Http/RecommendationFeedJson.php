<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\RecommendationFeedRow;
use App\Service\Recommendation\Feed\Model\FeedAnnotationVisibilityModel;
use App\Service\Recommendation\Feed\Model\ForYouFeedPageModel;

final class RecommendationFeedJson
{
    /**
     * A page of the for-you feed. `runId` and `runGeneratedAt` ride on every entry for the run divider; the reason and
     * the score only when the reader asked why, and always together (FeedAnnotationVisibilityModel).
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function page(ForYouFeedPageModel $page): array
    {
        return [
            'entries' => self::entries($page->rows, $page->visibility),
            'nextCursor' => $page->nextCursor,
        ];
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $rows, FeedAnnotationVisibilityModel $visibility): array
    {
        return array_map(static function (RecommendationFeedRow $row) use ($visibility): array {
            // ATOM, like the run report's forYou.generatedAt: the client compares them to find the newest run's picks.
            $entry = EntryJson::listRow($row->row) + [
                'runId' => $row->runId,
                'runGeneratedAt' => $row->runGeneratedAt?->format(\DateTimeInterface::ATOM),
            ];
            if (!$visibility->showExplanation) {
                return $entry;
            }

            return $entry + [
                'recommendationReason' => $row->reason,
                'recommendationScore' => $row->score,
            ];
        }, $rows);
    }
}
