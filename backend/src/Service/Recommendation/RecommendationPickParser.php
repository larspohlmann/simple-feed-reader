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
    /**
     * The top of the scale the prompt asks for. It is 1000 rather than 100 so
     * the model has room to separate candidates instead of stacking them on
     * one round number -- 29 of one run's 50 picks scored exactly 85 (#403).
     * Scores persisted before that change are on the old scale and are never
     * compared with these.
     */
    private const float MAXIMUM_SCORE = 1000.0;

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
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

        $picks = $this->salvagePicks($entries, $validIds);

        if ([] === $picks) {
            return PickParseResult::unusable();
        }

        return PickParseResult::usable($picks);
    }

    /**
     * @param array<mixed> $entries
     * @param list<int> $validIds
     *
     * @return list<RecommendationPick>
     */
    private function salvagePicks(array $entries, array $validIds): array
    {
        $picks = [];
        $seenIds = [];

        foreach ($entries as $entry) {
            $pick = $this->salvagePick($entry, $validIds, $seenIds);

            if (null === $pick) {
                continue;
            }

            $seenIds[$pick->entryId] = true;
            $picks[] = $pick;
        }

        return $picks;
    }

    /**
     * @param list<int> $validIds
     * @param array<int, true> $seenIds
     */
    private function salvagePick(mixed $entry, array $validIds, array $seenIds): ?RecommendationPick
    {
        if (!\is_array($entry)) {
            return null;
        }

        $entryId = $this->salvageEntryId($entry['id'] ?? null, $validIds);

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

    /** @param list<int> $validIds */
    private function salvageEntryId(mixed $id, array $validIds): ?int
    {
        if (\is_int($id)) {
            $candidate = $id;
        } elseif (\is_string($id) && ctype_digit($id)) {
            $candidate = (int) $id;
        } else {
            return null;
        }

        return \in_array($candidate, $validIds, true) ? $candidate : null;
    }

    private function salvageReason(mixed $reason): string
    {
        if (!\is_string($reason)) {
            return '';
        }

        return '' === trim($reason) ? '' : $reason;
    }
}
