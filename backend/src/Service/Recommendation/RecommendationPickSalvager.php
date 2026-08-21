<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Salvages the valid picks from a decoded model reply's `recommendations`
 * list -- the id/score/reason validation the batch parser and the
 * consolidation parser both apply to the same shape.
 *
 * A pick survives only with an id the model was shown, a numeric score clamped
 * to the scale, and at most one pick per id; a scoreless entry, an unknown id,
 * or a repeated id is dropped rather than failed. Partial credit is still
 * credit -- the caller keeps its valid picks even when some entries are
 * unusable, and treats an empty result as the unusable-reply signal.
 */
final readonly class RecommendationPickSalvager
{
    /**
     * The top of the scale the prompt asks for. It is 1000 rather than 100 so
     * the model has room to separate candidates instead of stacking them on
     * one round number -- 29 of one run's 50 picks scored exactly 85 (#403).
     * Scores persisted before that change are on the old scale and are never
     * compared with these.
     */
    private const float MAXIMUM_SCORE = 1000.0;

    /**
     * @param array<mixed> $entries
     * @param list<int>    $shownIds
     *
     * @return list<RecommendationPick>
     */
    public function salvage(array $entries, array $shownIds): array
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
     * @param list<int>        $shownIds
     * @param array<int, true> $seenIds
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
}
