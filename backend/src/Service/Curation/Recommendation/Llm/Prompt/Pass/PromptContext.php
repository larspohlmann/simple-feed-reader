<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Prompt\Pass;

use App\Service\Curation\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Curation\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class PromptContext
{
    public function __construct(
        public RecommendationHistoryModel $history,
        public EffectiveRecommendationSettingsModel $settings,
        public ?string $profile,
    ) {
    }
}
