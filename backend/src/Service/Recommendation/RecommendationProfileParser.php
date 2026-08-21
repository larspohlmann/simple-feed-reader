<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw distillation reply into a validated preference profile — the
 * same defensive boundary RecommendationPickParser and
 * RecommendationConsolidationParser are for their own replies. A profile is
 * unusable when the JSON does not parse, the shape is wrong, or the string it
 * carries is empty once trimmed: an empty profile tells the later phases
 * nothing they did not already know.
 */
final readonly class RecommendationProfileParser
{
    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    public function parse(string $content): ProfileParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return ProfileParseResult::unusable();
        }

        $profile = $decoded['profile'] ?? null;

        if (!\is_string($profile) || '' === trim($profile)) {
            return ProfileParseResult::unusable();
        }

        return ProfileParseResult::usable($profile);
    }
}
