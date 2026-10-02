<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** The LLM connection an engine without its own distillation borrows, with that connection's window and ceiling. */
final readonly class BorrowedProfileModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettingsModel $settings,
    ) {
    }
}
