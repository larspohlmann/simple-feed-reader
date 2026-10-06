<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Ai\Support\ReportedCost;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;

/** Never throws: a body that is not the documented shape decodes to no scores, which the parser rejects as unusable. */
final class RerankReplyDecoder
{
    /** @param list<int> $entryIds the request's entries, in the order its documents were sent */
    public static function decode(string $body, array $entryIds): ScoringReplyModel
    {
        $root = json_decode($body, true);
        $root = \is_array($root) ? $root : [];

        return new ScoringReplyModel(
            $body,
            self::scoresIn($root['results'] ?? null, $entryIds),
            new ProviderCallReceiptModel(
                self::textIn($root['id'] ?? null),
                self::textIn($root['model'] ?? null),
                self::usageIn($root['usage'] ?? null),
            ),
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, float> by entry id; none at all when an index repeats
     */
    private static function scoresIn(mixed $results, array $entryIds): array
    {
        if (!\is_array($results)) {
            return [];
        }

        $scores = [];
        foreach ($results as $result) {
            $entryId = self::entryIdOf($result, $entryIds);
            $relevance = self::relevanceOf($result);
            if (null === $entryId || null === $relevance) {
                continue;
            }
            if (isset($scores[$entryId])) {
                return [];
            }
            $scores[$entryId] = $relevance;
        }

        return $scores;
    }

    /** @param list<int> $entryIds */
    private static function entryIdOf(mixed $result, array $entryIds): ?int
    {
        $index = \is_array($result) ? ($result['index'] ?? null) : null;

        return \is_int($index) ? ($entryIds[$index] ?? null) : null;
    }

    private static function relevanceOf(mixed $result): ?float
    {
        $relevance = \is_array($result) ? ($result['relevance_score'] ?? null) : null;

        return \is_float($relevance) || \is_int($relevance) ? $relevance : null;
    }

    private static function usageIn(mixed $usage): ?ProviderCallUsageModel
    {
        if (!\is_array($usage)) {
            return null;
        }
        $totalTokens = $usage['total_tokens'] ?? null;

        return new ProviderCallUsageModel(
            promptTokens: \is_int($totalTokens) ? max(0, $totalTokens) : 0,
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: ReportedCost::nanoCreditsOf($usage['cost'] ?? null),
        );
    }

    private static function textIn(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function __construct()
    {
    }
}
