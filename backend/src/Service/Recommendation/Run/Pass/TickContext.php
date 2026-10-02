<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Model\RetryPlanModel;
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
        public ?TickContext $profileTick = null,
    ) {
    }

    public function borrowingProfileFrom(TickContext $profileTick): self
    {
        return new self($this->run, $this->connection, $this->engineKind, $this->settings, $this->driver, $profileTick);
    }

    /** The connection a provider failure this tick came from: the profile connection while it distils for the run. */
    public function connectionInFlight(): AiProviderSettings
    {
        return null !== $this->profileTick && null === $this->run->getProfileText()
            ? $this->profileTick->connection
            : $this->connection;
    }

    public function userId(): int
    {
        return $this->run->getUser()->requireId();
    }

    public function retryPlan(): RetryPlanModel
    {
        return $this->driver->retryPlan();
    }
}
