<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw assistant reply into validated picks — the defensive
 * boundary between an unreliable language model and the run state machine.
 *
 * A reply that parses keeps its valid picks even when some ids are invalid
 * or duplicated: partial credit is still credit. It is unusable — and only
 * unusable — when the JSON does not parse, the shape is wrong, or zero picks
 * survive validation. Tasks 10-11 branch on `usable`: a usable result is
 * recorded and the run advances; an unusable one triggers a retry with a
 * corrective message.
 */
final readonly class RecommendationPickParser
{
    /** @param list<int> $validIds */
    public function parse(string $content, array $validIds, int $limit): PickParseResult
    {
        $decoded = json_decode($this->stripCodeFence($content), true);

        if (!\is_array($decoded)) {
            return PickParseResult::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;

        if (!\is_array($entries)) {
            return PickParseResult::unusable();
        }

        $picks = $this->salvagePicks($entries, $validIds, $limit);

        if ([] === $picks) {
            return PickParseResult::unusable();
        }

        return PickParseResult::usable($picks);
    }

    private function stripCodeFence(string $content): string
    {
        $trimmed = trim($content);

        if (!str_starts_with($trimmed, '```') || !str_ends_with($trimmed, '```')) {
            return $trimmed;
        }

        $withoutClosingFence = substr($trimmed, 0, -3);
        $firstLineEnd = strpos($withoutClosingFence, "\n");

        if (false === $firstLineEnd) {
            return $withoutClosingFence;
        }

        return substr($withoutClosingFence, $firstLineEnd + 1);
    }

    /**
     * @param array<mixed> $entries
     * @param list<int> $validIds
     *
     * @return list<RecommendationPick>
     */
    private function salvagePicks(array $entries, array $validIds, int $limit): array
    {
        $picks = [];
        $seenIds = [];

        foreach ($entries as $entry) {
            if (\count($picks) >= $limit) {
                break;
            }

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

        return new RecommendationPick($entryId, $this->salvageReason($entry['reason'] ?? null));
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
