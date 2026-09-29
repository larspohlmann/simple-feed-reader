<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\Feed\Model\HistoryMonthModel;
use App\Service\Recommendation\Feed\Model\RunHistoryMonthPageModel;
use App\Service\Recommendation\Feed\Model\RunHistoryOverviewModel;

/**
 * The run history's overview card and month pages, fed with the repository's scalar rows, not runs, which carry the
 * candidate pool and replies. `durationSeconds` is computed here so no client subtracts timestamps across clocks.
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
    public static function overview(RunHistoryOverviewModel $overview): array
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
    public static function monthPage(RunHistoryMonthPageModel $page): array
    {
        return [
            'month' => $page->month,
            'runs' => array_map(self::row(...), $page->rows),
            'nextCursor' => $page->nextCursor,
        ];
    }

    /** @return array{month: string, runCount: int, costNanoCredits: ?int} */
    private static function monthSummary(HistoryMonthModel $month): array
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
            'status' => $run['status']->value,
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
     * Read off the status, not the column: resume() puts a failed run back to RUNNING and keeps the failed attempt's
     * completedAt, which must not appear beside a RUNNING badge.
     *
     * @param HistoryRow $run
     */
    private static function completionOf(array $run): ?\DateTimeImmutable
    {
        if (!$run['status']->isTerminal()) {
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
     * An int or null, whichever database answered: a scalar query may return the BIGINT as the driver's string.
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
