<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw consolidation reply into validated picks and duplicate ids.
 * The consolidation call answers both questions in a single reply, so this
 * parser reads both: it salvages the picks through the shared
 * RecommendationPickSalvager the batch parser also uses, then layers on the
 * duplicate-id list and the duplicate-share guard PlausibleDuplicateShare
 * enforces.
 *
 * A reply that parses keeps its valid picks even when some ids are invalid,
 * duplicated, or scoreless: partial credit is still credit, exactly as it is
 * for a batch reply. It is unusable when the JSON does not parse, the
 * `recommendations` shape is wrong, zero picks survive validation, or the
 * duplicate share is implausible -- that last case discards the picks too,
 * because a reply that cannot be trusted about duplicates was not read
 * carefully enough to be trusted about scores either (#396, #493).
 */
final readonly class RecommendationConsolidationParser
{
    public function __construct(
        private ModelReplyJsonDecoder $decoder,
        private RecommendationPickSalvager $salvager,
    ) {
    }

    /** @param list<int> $shownIds */
    public function parse(string $content, array $shownIds): ConsolidationParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return ConsolidationParseResult::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;

        if (!\is_array($entries)) {
            return ConsolidationParseResult::unusable();
        }

        $picks = $this->salvager->salvage($entries, $shownIds);

        if ([] === $picks) {
            return ConsolidationParseResult::unusable();
        }

        $duplicateIds = $this->salvageDuplicateIds($decoded['duplicates'] ?? [], $shownIds);

        if (PlausibleDuplicateShare::exceededBy(\count($duplicateIds), \count($shownIds))) {
            return ConsolidationParseResult::unusable();
        }

        return ConsolidationParseResult::usable($picks, $duplicateIds);
    }

    /**
     * @param list<int> $shownIds
     *
     * @return list<int>
     */
    private function salvageDuplicateIds(mixed $duplicates, array $shownIds): array
    {
        if (!\is_array($duplicates)) {
            return [];
        }

        $kept = [];
        foreach ($duplicates as $id) {
            if (\is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (\is_int($id) && \in_array($id, $shownIds, true)) {
                $kept[$id] = true;
            }
        }

        return array_keys($kept);
    }
}
