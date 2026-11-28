<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Settings\Model;

use App\Service\Curation\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;

/** Everything the recommendation settings card shows, read from one load of the settings row. */
final readonly class RecommendationSettingsStateModel
{
    public function __construct(
        public EffectiveRecommendationSettingsModel $effective,
        public RecommendationEngineCapabilitiesModel $capabilities,
        public bool $workerAlive,
    ) {
    }
}
