<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Llm\Run\ProviderPhase;

use App\Service\Curation\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Curation\Recommendation\Run\Pass\TickContext;

interface ProviderPhaseInterface
{
    public function advance(TickContext $tick): RecommendationRunReportModel;
}
