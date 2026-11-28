<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Settings;

use App\Entity\User;
use App\Service\Curation\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Curation\Recommendation\Run\WorkerPresence;
use App\Service\Curation\Recommendation\Settings\Model\RecommendationSettingsStateModel;

final readonly class RecommendationSettingsStateReader
{
    public function __construct(
        private RecommendationSettingsResolver $resolver,
        private RecommendationEngineResolver $engines,
        private WorkerPresence $presence,
    ) {
    }

    public function readState(User $user): RecommendationSettingsStateModel
    {
        $account = $this->resolver->forAccount($user);

        return new RecommendationSettingsStateModel(
            $account->forConnection($user->getActiveAiProviderSettings()),
            $this->engines->resolveAccountCapabilities($user),
            $this->presence->hasPersistentRecommendationWorker(),
        );
    }
}
