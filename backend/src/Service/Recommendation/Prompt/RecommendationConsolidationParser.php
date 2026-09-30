<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Service\Recommendation\Prompt\Model\ConsolidationParseResultModel;

/**
 * Turns one consolidation reply into picks (RecommendationPickSalvager) and duplicate ids. An implausible duplicate
 * share (PlausibleDuplicateShare) discards the picks too: a reply wrong about duplicates is not trusted on scores.
 */
final readonly class RecommendationConsolidationParser
{
    public function __construct(
        private ModelReplyJsonDecoder $decoder,
        private RecommendationPickSalvager $salvager,
        private PlausibleDuplicateShare $duplicateShare,
    ) {
    }

    /** @param list<int> $shownIds */
    public function parse(string $content, array $shownIds): ConsolidationParseResultModel
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return ConsolidationParseResultModel::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;

        if (!\is_array($entries)) {
            return ConsolidationParseResultModel::unusable();
        }

        $picks = $this->salvager->salvage($entries, $shownIds);

        if ([] === $picks) {
            return ConsolidationParseResultModel::unusable();
        }

        $duplicateIds = $this->salvageDuplicateIds($decoded['duplicates'] ?? [], $shownIds);

        if ($this->duplicateShare->exceededBy(\count($duplicateIds), \count($shownIds))) {
            return ConsolidationParseResultModel::unusable();
        }

        return ConsolidationParseResultModel::usable($picks, $duplicateIds);
    }

    /**
     * A reply the provider cut short, read for the recommendations it finished. The schema puts the duplicates after
     * them, so none arrived.
     *
     * @param list<int> $shownIds
     */
    public function parseCutReply(string $content, array $shownIds): ConsolidationParseResultModel
    {
        // A cut reply's outer object never closes, so one that decodes was not cut inside its answer.
        if (null !== $this->decoder->decode($content)) {
            return ConsolidationParseResultModel::unusable();
        }

        $picks = $this->salvager->salvage($this->decoder->completeItemsOf($content, 'recommendations'), $shownIds);

        if ([] === $picks) {
            return ConsolidationParseResultModel::unusable();
        }

        return ConsolidationParseResultModel::usable($picks, []);
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
