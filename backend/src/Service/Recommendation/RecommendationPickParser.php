<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw assistant reply into validated picks — the defensive
 * boundary between an unreliable language model and the run state machine.
 *
 * A reply that parses keeps its valid picks even when some ids are invalid,
 * duplicated, or scoreless: partial credit is still credit. It is unusable —
 * and only unusable — when the JSON does not parse, the shape is wrong, or
 * zero picks survive validation. Tasks 10-11 branch on `usable`: a usable
 * result is recorded and the run advances; an unusable one triggers a retry
 * with a corrective message.
 */
final readonly class RecommendationPickParser
{
    public function __construct(
        private ModelReplyJsonDecoder $decoder,
        private RecommendationPickSalvager $salvager,
    ) {
    }

    /** @param list<int> $validIds */
    public function parse(string $content, array $validIds): PickParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return PickParseResult::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;

        if (!\is_array($entries)) {
            return PickParseResult::unusable();
        }

        $picks = $this->salvager->salvage($entries, $validIds);

        if ([] === $picks) {
            return PickParseResult::unusable();
        }

        return PickParseResult::usable($picks);
    }
}
