<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Prompt\Pass;

use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Curation\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class PromptContext
{
    /** @param list<ArticleLineModel> $favorites newest first */
    public function __construct(
        public array $favorites,
        public EffectiveRecommendationSettingsModel $settings,
        public ?string $profile,
    ) {
    }
}
