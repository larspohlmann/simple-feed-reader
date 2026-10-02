<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Run\ProviderPhase;

use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;

interface ProviderPhaseInterface
{
    public function advance(TickContext $tick): RecommendationRunReportModel;
}
