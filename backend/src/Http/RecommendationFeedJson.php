<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\RecommendationFeedRow;

final class RecommendationFeedJson
{
    /**
     * A page of the for-you feed. Each entry carries `runId` and
     * `runGeneratedAt` unconditionally — the run-boundary divider is a
     * normal-user feature (#348) — then the two debug annotations on
     * independent axes (#541, superseding #342's single-flag coupling):
     * `recommendationReason` iff the reader asked to see reasons, and
     * `recommendationScore` iff the debug setting is on.
     *
     * @param list<RecommendationFeedRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function page(array $rows, ?string $nextCursor, FeedAnnotationVisibility $visibility): array
    {
        return [
            'entries' => self::entries($rows, $visibility),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $rows, FeedAnnotationVisibility $visibility): array
    {
        return array_map(static function (RecommendationFeedRow $row) use ($visibility): array {
            // runId + runGeneratedAt are unconditional: the divider needs the
            // run's identity and generation time on every row. The ATOM format
            // matches the run report's forYou.generatedAt, so the client can tell
            // the newest run's picks from the rest by their generation instant (#348).
            $entry = EntryJson::one($row->row) + [
                'runId' => $row->runId,
                'runGeneratedAt' => $row->runGeneratedAt?->format(\DateTimeInterface::ATOM),
            ];
            if ($visibility->showReasons) {
                $entry += ['recommendationReason' => $row->reason];
            }
            if ($visibility->showScores) {
                $entry += ['recommendationScore' => $row->score];
            }

            return $entry;
        }, $rows);
    }
}
