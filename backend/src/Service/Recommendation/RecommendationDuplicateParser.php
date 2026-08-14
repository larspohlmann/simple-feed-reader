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
 * one naming more than PlausibleDuplicateShare allows is read as a mistake
 * rather than as an answer, and goes back as unusable — the dedup phase then
 * retries once with a corrective tail and, failing that, degrades to the
 * undeduped list. The prompt states the same bound, so this rejects only a
 * reply that was told the rule and broke it anyway.
 */
final readonly class RecommendationDuplicateParser
{
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

        if (PlausibleDuplicateShare::exceededBy(\count($duplicateIds), \count($shownIds))) {
            return DuplicateParseResult::unusable();
        }

        return DuplicateParseResult::usable($duplicateIds);
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
