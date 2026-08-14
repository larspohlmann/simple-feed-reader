<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw dedup reply into validated duplicate ids — the same
 * defensive boundary RecommendationPickParser is for pick replies. Ids the
 * model was never shown are ignored rather than poisoning the reply:
 * partial credit is still credit.
 *
 * A well-formed reply can still be wrong, and this parser judges that too:
 * see IMPLAUSIBLE_DUPLICATE_PERCENT.
 */
final readonly class RecommendationDuplicateParser
{
    /**
     * A reply naming more than half of the entries it was shown is read as a
     * mistake, not as an answer, and goes back as unusable — the dedup phase
     * then retries once with a corrective tail and, failing that, degrades to
     * the undeduped list.
     *
     * The number is a plausibility bound, not a measurement. The dedup call
     * sees twice the final list size, so it may legitimately name up to half
     * of it without shortening the final list at all; above that it is
     * claiming the reader's whole pool collapses into a handful of stories.
     * In production 21 of 26 runs came back short, one down to 7 of 100 —
     * and a run 25 minutes earlier, on the same feeds with the same settings,
     * returned a full, diverse 50. Over-flagging, not a duplicate-heavy pool
     * (#396).
     */
    private const int IMPLAUSIBLE_DUPLICATE_PERCENT = 50;

    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    /** @param list<int> $shownIds */
    public function parse(string $content, array $shownIds): DuplicateParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return DuplicateParseResult::unusable();
        }

        $duplicates = $decoded['duplicates'] ?? null;

        if (!\is_array($duplicates)) {
            return DuplicateParseResult::unusable();
        }

        $duplicateIds = $this->salvageIds($duplicates, $shownIds);

        if (self::namesTooMany($duplicateIds, $shownIds)) {
            return DuplicateParseResult::unusable();
        }

        return DuplicateParseResult::usable($duplicateIds);
    }

    /**
     * @param list<int> $duplicateIds
     * @param list<int> $shownIds
     */
    private static function namesTooMany(array $duplicateIds, array $shownIds): bool
    {
        return \count($duplicateIds) * 100 > \count($shownIds) * self::IMPLAUSIBLE_DUPLICATE_PERCENT;
    }

    /**
     * @param array<mixed> $duplicates
     * @param list<int>    $shownIds
     *
     * @return list<int>
     */
    private function salvageIds(array $duplicates, array $shownIds): array
    {
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
