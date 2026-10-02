<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Recommendation\Run\Model\BorrowedProfileModel;
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
        public ?BorrowedProfileModel $borrowedProfile = null,
    ) {
    }

    public function borrowingProfileFrom(BorrowedProfileModel $borrowed): self
    {
        return new self($this->run, $this->connection, $this->engineKind, $this->settings, $this->driver, $borrowed);
    }

    /** The tick a borrowed distillation runs on: the profile connection's own. */
    public function profileTick(): ?self
    {
        $borrowed = $this->borrowedProfile;
        if (null === $borrowed) {
            return null;
        }

        return new self(
            $this->run,
            $borrowed->connection,
            $borrowed->engineKind,
            $borrowed->settings,
            $this->driver,
        );
    }

    /** The connection a provider failure this tick came from: the profile connection while it distils for the run. */
    public function connectionInFlight(): AiProviderSettings
    {
        return null !== $this->borrowedProfile && null === $this->run->getProfileText()
            ? $this->borrowedProfile->connection
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
