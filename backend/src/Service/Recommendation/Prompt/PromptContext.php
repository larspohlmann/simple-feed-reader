<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;

final readonly class PromptContext
{
    public function __construct(
        public RecommendationHistory $history,
        public EffectiveRecommendationSettings $settings,
        public ?string $profile,
    ) {
    }
}
