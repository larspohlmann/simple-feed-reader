<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\RecommendationFeedRow;

final class RecommendationFeedJson
{
    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function page(array $rows, ?string $nextCursor): array
    {
        return [
            'entries' => self::entries($rows, withScores: false),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * Same shape as page(), plus each entry's `recommendationScore` — used
     * only while the caller's debug setting is on (#321).
     *
     * @param list<RecommendationFeedRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function pageWithScores(array $rows, ?string $nextCursor): array
    {
        return [
            'entries' => self::entries($rows, withScores: true),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $rows, bool $withScores): array
    {
        return array_map(static function (RecommendationFeedRow $row) use ($withScores): array {
            $entry = EntryJson::one($row->row) + ['recommendationReason' => $row->reason];

            return $withScores ? $entry + ['recommendationScore' => $row->score] : $entry;
        }, $rows);
    }
}
