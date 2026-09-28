<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\ProviderPhase;

use App\Service\Recommendation\Run\RecommendationRunReport;
use App\Service\Recommendation\Run\TickContext;

interface ProviderPhaseInterface
{
    public function advance(TickContext $tick): RecommendationRunReport;
}
