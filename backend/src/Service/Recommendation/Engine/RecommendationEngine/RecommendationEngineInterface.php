<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine\RecommendationEngine;

use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way to turn a run's candidates into a ranked list, keyed in RecommendationEngineResolver's locator by its
 * RecommendationEngineKind value (#[AsTaggedItem]).
 */
#[AutoconfigureTag('app.recommendation_engine')]
interface RecommendationEngineInterface
{
    /**
     * The frozen plan the run advances through, batch by batch.
     *
     * @param list<PromptLineModel> $candidates
     *
     * @return list<list<int>>
     */
    public function packBatches(array $candidates, TickContext $tick): array;

    /** One tick of a running run that is not waiting out a rate limit. */
    public function advance(TickContext $tick): RecommendationRunReportModel;

    public function capabilities(): RecommendationEngineCapabilitiesModel;
}
