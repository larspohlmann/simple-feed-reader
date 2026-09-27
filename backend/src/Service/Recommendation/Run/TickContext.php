<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Service\Ai\Completion\Reasoning;
use App\Service\Ai\Completion\RetryPlan;
use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;

final readonly class TickContext
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public RecommendationRun $run,
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettings $settings,
        public TickDriver $driver,
    ) {
    }

    public function userId(): int
    {
        return $this->run->getUser()->requireId();
    }

    public function retryPlan(): RetryPlan
    {
        return $this->driver->retryPlan();
    }

    public function reasoning(): Reasoning
    {
        return Reasoning::preferredBy($this->connection);
    }
}
