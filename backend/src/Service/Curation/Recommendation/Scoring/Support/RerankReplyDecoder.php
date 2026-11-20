<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Scoring\Support;

use App\Service\Curation\Ai\Model\ProviderCallReceiptModel;
use App\Service\Curation\Ai\Model\ProviderCallUsageModel;
use App\Service\Curation\Ai\Support\ReplyField;
use App\Service\Curation\Ai\Support\ReportedCost;
use App\Service\Curation\Recommendation\Scoring\Model\ScoringReplyModel;

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
            self::parseScores($root['results'] ?? null, $entryIds),
            new ProviderCallReceiptModel(
                ReplyField::text($root['id'] ?? null),
                ReplyField::text($root['model'] ?? null),
                self::parseUsage($root['usage'] ?? null),
            ),
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, float> by entry id; none at all when an entry is answered twice
     */
    private static function parseScores(mixed $results, array $entryIds): array
    {
        if (!\is_array($results) || !array_is_list($results)) {
            return [];
        }

        $relevances = [];
        foreach ($results as $result) {
            $entryId = self::findEntryId($result, $entryIds);
            if (null === $entryId) {
                continue;
            }
            if (\array_key_exists($entryId, $relevances)) {
                return [];
            }
            $relevances[$entryId] = self::parseRelevance($result);
        }

        return array_filter($relevances, static fn (?float $relevance): bool => null !== $relevance);
    }

    /** @param list<int> $entryIds */
    private static function findEntryId(mixed $result, array $entryIds): ?int
    {
        $index = \is_array($result) ? ($result['index'] ?? null) : null;

        return \is_int($index) ? ($entryIds[$index] ?? null) : null;
    }

    private static function parseRelevance(mixed $result): ?float
    {
        $relevance = \is_array($result) ? ($result['relevance_score'] ?? null) : null;

        return \is_float($relevance) || \is_int($relevance) ? $relevance : null;
    }

    private static function parseUsage(mixed $usage): ?ProviderCallUsageModel
    {
        if (!\is_array($usage)) {
            return null;
        }
        return new ProviderCallUsageModel(
            promptTokens: ReplyField::count($usage['total_tokens'] ?? null),
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: ReportedCost::parseNanoCredits($usage['cost'] ?? null),
        );
    }

    private function __construct()
    {
    }
}
