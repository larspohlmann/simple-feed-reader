<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Ai\Support\ReplyField;
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
                ReplyField::text($root['id'] ?? null),
                ReplyField::text($root['model'] ?? null),
                self::usageIn($root['usage'] ?? null),
            ),
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, float> by entry id; none at all when an entry is answered twice
     */
    private static function scoresIn(mixed $results, array $entryIds): array
    {
        if (!\is_array($results) || !array_is_list($results)) {
            return [];
        }

        $relevances = [];
        foreach ($results as $result) {
            $entryId = self::entryIdOf($result, $entryIds);
            if (null === $entryId) {
                continue;
            }
            if (\array_key_exists($entryId, $relevances)) {
                return [];
            }
            $relevances[$entryId] = self::relevanceOf($result);
        }

        return array_filter($relevances, static fn (?float $relevance): bool => null !== $relevance);
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
        return new ProviderCallUsageModel(
            promptTokens: ReplyField::count($usage['total_tokens'] ?? null),
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: ReportedCost::nanoCreditsOf($usage['cost'] ?? null),
        );
    }

    private function __construct()
    {
    }
}
