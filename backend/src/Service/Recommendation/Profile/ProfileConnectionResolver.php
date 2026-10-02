<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

final readonly class ProfileConnectionResolver
{
    public function __construct(
        private AiProviderSettingsRepository $aiProviderSettings,
        private RecommendationEngineResolver $engines,
    ) {
    }

    /** The profile connection an active connection's engine borrows; null when it distils its own or has none. */
    public function borrowedFor(AiProviderSettings $active): ?AiProviderSettings
    {
        return RecommendationProfileSource::Borrowed === $this->engines->capabilitiesFor($active)->profileSource
            ? $this->findUsableFor($active->getUser())
            : null;
    }

    public function findUsableFor(User $user): ?AiProviderSettings
    {
        $connection = $this->aiProviderSettings->findProfileSourceFor($user);

        return null !== $connection && $this->canBuildProfiles($connection) ? $connection : null;
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection)
            && RecommendationProfileSource::Own === $this->engines->capabilitiesFor($connection)->profileSource;
    }
}
