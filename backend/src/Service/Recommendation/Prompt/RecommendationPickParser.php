<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Service\Recommendation\Prompt\Model\PickParseResultModel;

/**
 * Turns one batch reply into validated picks, the defensive boundary between the model and the run. Unusable only
 * when the JSON does not parse, the shape is wrong, or no pick survives.
 */
final readonly class RecommendationPickParser
{
    public function __construct(
        private ModelReplyJsonDecoder $decoder,
        private RecommendationPickSalvager $salvager,
    ) {
    }

    /** @param list<int> $validIds */
    public function parse(string $content, array $validIds): PickParseResultModel
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return PickParseResultModel::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;

        if (!\is_array($entries)) {
            return PickParseResultModel::unusable();
        }

        $picks = $this->salvager->salvage($entries, $validIds);

        if ([] === $picks) {
            return PickParseResultModel::unusable();
        }

        return PickParseResultModel::usable($picks);
    }
}
