<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Ai\Completion\Model\RetryPlanModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class TickContext
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public RecommendationRun $run,
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettingsModel $settings,
        public TickDriver $driver,
    ) {
    }

    public function userId(): int
    {
        return $this->run->getUser()->requireId();
    }

    public function retryPlan(): RetryPlanModel
    {
        return $this->driver->retryPlan();
    }

    public function reasoning(): Reasoning
    {
        return Reasoning::preferredBy($this->connection);
    }
}
