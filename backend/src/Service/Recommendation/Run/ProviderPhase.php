<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

interface ProviderPhase
{
    public function advance(TickContext $tick): RecommendationRunReport;
}
