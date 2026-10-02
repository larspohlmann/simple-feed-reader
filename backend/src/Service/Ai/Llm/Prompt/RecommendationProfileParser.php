<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Prompt;

use App\Service\Ai\Llm\Prompt\Model\ProfileParseResultModel;

/**
 * Turns one distillation reply into a preference profile. Unusable when the JSON does not parse, the shape is wrong,
 * or the trimmed string is empty: an empty profile tells the later phases nothing.
 */
final readonly class RecommendationProfileParser
{
    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    public function parse(string $content): ProfileParseResultModel
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return ProfileParseResultModel::unusable();
        }

        $profile = $decoded['profile'] ?? null;

        if (!\is_string($profile) || '' === trim($profile)) {
            return ProfileParseResultModel::unusable();
        }

        return ProfileParseResultModel::usable($profile);
    }
}
