<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw consolidation reply into validated picks and duplicate ids —
 * the pick-salvaging boundary RecommendationPickParser is, and the
 * duplicate-share guard RecommendationDuplicateParser is, combined into one
 * parser because the consolidation call answers both questions in a single
 * reply.
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
    /**
     * The top of the scale the prompt asks for, matching
     * RecommendationPickParser's own scale (#403).
     */
    private const float MAXIMUM_SCORE = 1000.0;

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
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

        $picks = $this->salvagePicks($entries, $shownIds);

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
     * @param array<mixed> $entries
     * @param list<int>    $shownIds
     *
     * @return list<RecommendationPick>
     */
    private function salvagePicks(array $entries, array $shownIds): array
    {
        $picks = [];
        $seenIds = [];

        foreach ($entries as $entry) {
            $pick = $this->salvagePick($entry, $shownIds, $seenIds);

            if (null === $pick) {
                continue;
            }

            $seenIds[$pick->entryId] = true;
            $picks[] = $pick;
        }

        return $picks;
    }

    /**
     * @param list<int>         $shownIds
     * @param array<int, true>  $seenIds
     */
    private function salvagePick(mixed $entry, array $shownIds, array $seenIds): ?RecommendationPick
    {
        if (!\is_array($entry)) {
            return null;
        }

        $entryId = $this->salvageEntryId($entry['id'] ?? null, $shownIds);

        if (null === $entryId || isset($seenIds[$entryId])) {
            return null;
        }

        $score = $this->salvageScore($entry['score'] ?? null);

        if (null === $score) {
            return null;
        }

        return new RecommendationPick($entryId, $score, $this->salvageReason($entry['reason'] ?? null));
    }

    private function salvageScore(mixed $score): ?int
    {
        if (\is_int($score) || \is_float($score)) {
            $numeric = (float) $score;
        } elseif (\is_string($score) && is_numeric($score)) {
            $numeric = (float) $score;
        } else {
            return null;
        }

        return (int) min(self::MAXIMUM_SCORE, max(0.0, round($numeric)));
    }

    /** @param list<int> $shownIds */
    private function salvageEntryId(mixed $id, array $shownIds): ?int
    {
        if (\is_int($id)) {
            $candidate = $id;
        } elseif (\is_string($id) && ctype_digit($id)) {
            $candidate = (int) $id;
        } else {
            return null;
        }

        return \in_array($candidate, $shownIds, true) ? $candidate : null;
    }

    private function salvageReason(mixed $reason): string
    {
        if (!\is_string($reason)) {
            return '';
        }

        return '' === trim($reason) ? '' : $reason;
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
