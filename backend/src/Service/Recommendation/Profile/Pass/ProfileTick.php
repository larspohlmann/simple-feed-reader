<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileRun;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Run\Model\ProviderCallRouteModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/** What one profile tick reads about its account, read once before the tick does anything. */
final readonly class ProfileTick
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public ProfileRun $profileRun,
        public AiProviderSettings $connection,
        public EffectiveRecommendationSettingsModel $settings,
        public ProfileInputsModel $inputs,
        public TickDriver $driver,
    ) {
    }

    public function callRoute(): ProviderCallRouteModel
    {
        return new ProviderCallRouteModel($this->connection, $this->driver->retryPlan());
    }
}
