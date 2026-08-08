<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw dedup reply into validated duplicate ids — the same
 * defensive boundary RecommendationPickParser is for pick replies. Ids the
 * model was never shown are ignored rather than poisoning the reply:
 * partial credit is still credit.
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

        return DuplicateParseResult::usable($this->salvageIds($duplicates, $shownIds));
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
