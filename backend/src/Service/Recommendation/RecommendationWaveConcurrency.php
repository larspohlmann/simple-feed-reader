<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;

/**
 * The run's wave concurrency against its connection's configured ceiling
 * (#947): a 429 anywhere in a wave halves what RecommendationRunAdvancer's
 * waveSize() reads back on every later tick, until the run finishes.
 * Lifted out of the advancer the same way RecommendationRunDeferral was
 * (#947 final review): one more seam PHPMD's ExcessiveClassComplexity forced
 * out once the batch phase gained its own halve-and-defer/halve-and-strike
 * branches alongside distillation's and consolidation's.
 */
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
