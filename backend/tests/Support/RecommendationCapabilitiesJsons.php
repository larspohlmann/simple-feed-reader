<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\RecommendationCapabilitiesJson;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RecommendationCapabilitiesJsons
{
    public const array LLM = [
        'reasons' => true,
        'tuningFields' => [
            'contextWindow',
            'batchSize',
            'suppressReasoning',
            'slowModel',
            'maxBatchSize',
            'batchConcurrency',
        ],
    ];

    /** Over an empty engine locator: the capabilities come from the kind, never from an engine. */
    public static function ofTheKind(): RecommendationCapabilitiesJson
    {
        return new RecommendationCapabilitiesJson(new RecommendationEngineResolver(new ServiceLocator([])));
    }
}
