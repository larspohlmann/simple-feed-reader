<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\HistoryMonth;
use App\Service\Recommendation\RunHistoryMonthPage;
use App\Service\Recommendation\RunHistoryOverview;

/**
 * The wire shape of the run history (#409): the overview card (the account's
 * all-time cost total, one summary per calendar month, and the newest month's
 * first page) and the further month pages it expands into.
 *
 * Fed with the repository's scalar projection, not runs: the entity carries the
 * frozen candidate pool, every pick's reason and the last rejected provider
 * reply, none of which belongs on a path that formats twelve numbers.
 *
 * `durationSeconds` is computed here, not left to the client (the rule
 * RecommendationRunStatusJson follows) — the client never subtracts timestamps
 * across machines. `status` goes out as the raw wire vocabulary, untranslated,
 * the same convention the #309 debug log records.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 * @phpstan-type MonthPagePayload array{
 *     month: string,
 *     runs: list<array<string, mixed>>,
 *     nextCursor: ?int,
 * }
 * @phpstan-type OverviewPayload array{
 *     totalCostNanoCredits: ?int,
 *     months: list<array<string, mixed>>,
 *     latest: ?MonthPagePayload,
 * }
 */
final class RecommendationRunHistoryJson
{
    /** @return OverviewPayload */
    public static function overview(RunHistoryOverview $overview): array
    {
        return [
            // The account's whole spend, not the sum of the page above it. A
            // total that silently means "of the last fifty" is a wrong number,
            // not a cheaper one.
            'totalCostNanoCredits' => $overview->totalCostNanoCredits,
            'months' => array_map(self::monthSummary(...), $overview->months),
            'latest' => null === $overview->latest ? null : self::monthPage($overview->latest),
        ];
    }

    /** @return MonthPagePayload */
    public static function monthPage(RunHistoryMonthPage $page): array
    {
        return [
            'month' => $page->month,
            'runs' => array_map(self::row(...), $page->rows),
            'nextCursor' => $page->nextCursor,
        ];
    }

    /** @return array{month: string, runCount: int, costNanoCredits: ?int} */
    private static function monthSummary(HistoryMonth $month): array
    {
        return [
            'month' => $month->month,
            'runCount' => $month->runCount,
            'costNanoCredits' => $month->costNanoCredits,
        ];
    }

    /**
     * @param HistoryRow $run
     *
     * @return array<string, mixed>
     */
    private static function row(array $run): array
    {
        $completedAt = self::completionOf($run);

        return [
            'id' => $run['id'],
            'status' => $run['status'],
            'providerHost' => $run['providerHost'],
            'model' => $run['model'],
            'createdAt' => $run['createdAt']->format(\DateTimeInterface::ATOM),
            'completedAt' => $completedAt?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => self::durationSeconds($run['createdAt'], $completedAt),
            'promptTokens' => $run['promptTokens'],
            'completionTokens' => $run['completionTokens'],
            'reasoningTokens' => $run['reasoningTokens'],
            'cachedTokens' => $run['cachedTokens'],
            'costNanoCredits' => self::costNanoCredits($run),
        ];
    }

    /**
     * When the run ended, or null while it has not ended. Read off the status
     * rather than off the column, because the two disagree: resume() puts a
     * failed run back into RUNNING and deliberately leaves the completedAt of
     * the attempt that failed standing. Reporting that timestamp would put a
     * completion time — and the duration derived from it — beside a RUNNING
     * badge, and both would be measuring the wrong attempt.
     *
     * @param HistoryRow $run
     */
    private static function completionOf(array $run): ?\DateTimeImmutable
    {
        if (!\in_array($run['status'], RecommendationRun::TERMINAL_STATUSES, true)) {
            return null;
        }

        return $run['completedAt'];
    }

    /**
     * How long the run took, or null while it has not finished. Clamped at 0
     * so a clock skew can never surface as a negative duration.
     */
    private static function durationSeconds(
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt,
    ): ?int {
        if (null === $completedAt) {
            return null;
        }

        return max(0, $completedAt->getTimestamp() - $createdAt->getTimestamp());
    }

    /**
     * The price as the wire contract states it: an integer, or null when no
     * call of the run reported one. BIGINT hydrates as a PHP int for every
     * value nano-credits can reach, but a scalar query may still hand the
     * column back as the driver's own string, so the payload's type is pinned
     * here rather than left to whichever database answered.
     *
     * @param HistoryRow $run
     */
    private static function costNanoCredits(array $run): ?int
    {
        $costNanoCredits = $run['costNanoCredits'];

        return null === $costNanoCredits ? null : (int) $costNanoCredits;
    }

    private function __construct()
    {
    }
}
