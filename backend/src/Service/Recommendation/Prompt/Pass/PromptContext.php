<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Pass;

use App\Service\Recommendation\Prompt\Model\RecommendationHistoryModel;
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
