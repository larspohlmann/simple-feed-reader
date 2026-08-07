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
            'entries' => array_map(static fn (RecommendationFeedRow $row): array => EntryJson::one($row->row) + [
                'recommendationReason' => $row->reason,
            ], $rows),
            'nextCursor' => $nextCursor,
        ];
    }
}
