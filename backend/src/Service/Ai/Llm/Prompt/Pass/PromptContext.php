<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Prompt\Pass;

use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class PromptContext
{
    public function __construct(
        public RecommendationHistoryModel $history,
        public EffectiveRecommendationSettingsModel $settings,
        public ?string $profile,
    ) {
    }
}
