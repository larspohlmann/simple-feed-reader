<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\ProfileDistiller;

use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;

/** One tick of a run's distillation, recorded on the run; an engine that cannot distil borrows it. */
interface ProfileDistillerInterface
{
    public function advance(TickContext $tick): RecommendationRunReportModel;
}
