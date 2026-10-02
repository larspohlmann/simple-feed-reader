<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

final readonly class ProfileConnectionResolver
{
    public function __construct(private RecommendationEngineResolver $engines)
    {
    }

    /**
     * The connection the active one borrows its profile from: null when it builds its own, chose none, or chose one
     * that can no longer build profiles.
     */
    public function borrowedFor(AiProviderSettings $active): ?AiProviderSettings
    {
        $connection = $active->getProfileConnection();
        if (null === $connection || !$this->borrows($active)) {
            return null;
        }

        return $this->canBuildProfiles($connection) ? $connection : null;
    }

    public function borrows(AiProviderSettings $connection): bool
    {
        return RecommendationProfileSource::Borrowed === $this->engines->capabilitiesFor($connection)->profileSource;
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection)
            && RecommendationProfileSource::Own === $this->engines->capabilitiesFor($connection)->profileSource;
    }
}
