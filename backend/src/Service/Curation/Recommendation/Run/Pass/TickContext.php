<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Service\Curation\Ai\Model\RetryPlanModel;
use App\Service\Curation\Recommendation\Run\Model\ProviderCallRouteModel;
use App\Service\Curation\Recommendation\Run\Model\TickDriver;
use App\Service\Curation\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

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

    public function requireScoringProtocol(): ScoringProtocol
    {
        return $this->connection->getScoringProtocol()
            ?? throw new \LogicException('This tick\'s connection speaks no scoring protocol.');
    }

    public function requireScoringContextWindow(): int
    {
        return $this->connection->getModelContextWindow()
            ?? throw new \LogicException('This tick\'s connection stores no context window for its scoring model.');
    }
}
