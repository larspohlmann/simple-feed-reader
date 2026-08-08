<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The three inputs RecommendationPromptBuilder reads to size a batch: the
 * resolved context window, where it came from, and the optional expert
 * batchCount override. Bundled into one value object so adding batchCount
 * (#321) does not push EffectiveRecommendationSettings's constructor past
 * PHPMD's parameter-count ceiling.
 */
final readonly class RecommendationPackingSettings
{
    public function __construct(
        public int $contextWindow,
        public string $contextWindowSource,
        public ?int $batchCount,
    ) {
    }
}
