<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\RecommendationProfileSource;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Support\AiReadiness;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;

/** Which saved connection builds the account's profile. */
final readonly class ProfileConnections
{
    public const string MISSING = 'No connection can build your profile. Choose one under Settings → Profile.';

    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private AiProviderConfigurator $configurator,
        private RecommendationEngineResolver $engines,
    ) {
    }

    public function usableFor(User $user): ?AiProviderSettings
    {
        return $this->usableGiven($user, $this->recommendationSettings->findForUser($user));
    }

    /** The chosen connection if it can build a profile, else none; with nothing chosen, the active one if it can. */
    public function usableGiven(User $user, ?RecommendationSettings $storedSettings): ?AiProviderSettings
    {
        $candidate = $storedSettings?->profileSettings()->connection ?? $this->configurator->settingsFor($user);

        return null !== $candidate && $this->canBuildProfiles($candidate) ? $candidate : null;
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::of($connection)
            && RecommendationProfileSource::Own === $this->engines->capabilitiesFor($connection)->profileSource;
    }

    /** @return list<AiProviderSettings> */
    public function candidatesFor(User $user): array
    {
        return array_values(array_filter(
            $this->configurator->listConfigurations($user),
            $this->canBuildProfiles(...),
        ));
    }
}
