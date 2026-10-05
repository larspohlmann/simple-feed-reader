<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Recommendation\Run\Model\ProviderCallRouteModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

final readonly class TickContext
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public RecommendationRun $run,
        public AiProviderSettings $connection,
        public RecommendationEngineKind $engineKind,
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

    public function callRoute(): ProviderCallRouteModel
    {
        return new ProviderCallRouteModel($this->connection, $this->retryPlan());
    }

    public function scoringProtocol(): ?ScoringProtocol
    {
        return $this->connection->getScoringProtocol();
    }
}
