<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRun;

/**
 * The wire shape of the run history (#409): one row per run and the account's
 * all-time cost total.
 *
 * `durationSeconds` is computed here rather than left to the client, the rule
 * RecommendationRunStatusJson already follows — the client never subtracts
 * timestamps across machines.
 *
 * `status` goes out as the raw wire vocabulary, untranslated, the same
 * convention the #309 debug log records.
 */
final class RecommendationRunHistoryJson
{
    /**
     * @param list<RecommendationRun> $runs
     *
     * @return array{runs: list<array<string, mixed>>, totalCostNanoCredits: ?int}
     */
    public static function payload(array $runs, ?int $totalCostNanoCredits): array
    {
        return [
            'runs' => array_map(self::row(...), $runs),
            // The account's whole spend, not the sum of the page above it. A
            // total that silently means "of the last fifty" is a wrong number,
            // not a cheaper one.
            'totalCostNanoCredits' => $totalCostNanoCredits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(RecommendationRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'providerHost' => $run->getProviderHost(),
            'model' => $run->getModel(),
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => self::durationSeconds($run),
            'promptTokens' => $run->getPromptTokens(),
            'completionTokens' => $run->getCompletionTokens(),
            'reasoningTokens' => $run->getReasoningTokens(),
            'cachedTokens' => $run->getCachedTokens(),
            'costNanoCredits' => $run->getCostNanoCredits(),
        ];
    }

    /**
     * How long the run took, or null while it has not finished. Clamped at 0
     * so a clock skew can never surface as a negative duration.
     */
    private static function durationSeconds(RecommendationRun $run): ?int
    {
        $completedAt = $run->getCompletedAt();

        if (null === $completedAt) {
            return null;
        }

        return max(0, $completedAt->getTimestamp() - $run->getCreatedAt()->getTimestamp());
    }

    private function __construct()
    {
    }
}
