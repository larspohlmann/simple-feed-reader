<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;

/** The run's wave concurrency against its connection's ceiling: a 429 halves what BatchPhase reads back (#947). */
final readonly class RecommendationWaveConcurrency
{
    public function halve(RecommendationRun $run, AiProviderSettings $settings): void
    {
        $run->reduceWaveConcurrency($settings->cappedBatchConcurrency());
    }

    public function cap(RecommendationRun $run, AiProviderSettings $settings): int
    {
        return $run->waveConcurrencyCap($settings->cappedBatchConcurrency());
    }
}
