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
            'entries' => self::entries($rows, withDebugAnnotations: false),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * Same shape as page(), plus each entry's `recommendationReason` and
     * `recommendationScore` — the run's debug annotations, sent only while the
     * caller's debug setting is on (#321; the reason joined the score behind the
     * debug flag in #342, so "keep debug data off" hides both, not just the
     * score). `runId` and `runGeneratedAt` are NOT debug-gated: both pages carry
     * them, because the run-boundary divider is a normal-user feature (#348).
     *
     * @param list<RecommendationFeedRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function pageWithScores(array $rows, ?string $nextCursor): array
    {
        return [
            'entries' => self::entries($rows, withDebugAnnotations: true),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $rows, bool $withDebugAnnotations): array
    {
        return array_map(static function (RecommendationFeedRow $row) use ($withDebugAnnotations): array {
            // runId + runGeneratedAt are unconditional: the divider needs the
            // run's identity and generation time on every row. The ATOM format
            // matches the run report's forYou.generatedAt exactly, so the client
            // can compare the two by string equality (#348).
            $entry = EntryJson::one($row->row) + [
                'runId' => $row->runId,
                'runGeneratedAt' => $row->runGeneratedAt?->format(\DateTimeInterface::ATOM),
            ];
            if (!$withDebugAnnotations) {
                return $entry;
            }

            return $entry + [
                'recommendationReason' => $row->reason,
                'recommendationScore' => $row->score,
            ];
        }, $rows);
    }
}
