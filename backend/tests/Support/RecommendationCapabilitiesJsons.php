<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\RecommendationCapabilitiesJson;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;

final class RecommendationCapabilitiesJsons
{
    public static function reporting(
        RecommendationEngineCapabilitiesModel $capabilities,
    ): RecommendationCapabilitiesJson {
        return new RecommendationCapabilitiesJson(ScriptedRecommendationEngine::reporting($capabilities)->resolver());
    }
}
