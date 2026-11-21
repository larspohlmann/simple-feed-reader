<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\RecommendationProfileSource;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Ai\ConnectionPicks;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Curation\Ai\Exception\ConnectionPickRejectedException;
use App\Service\Curation\Ai\Support\AiReadiness;
use App\Service\Curation\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Curation\Recommendation\Exception\ProfileConnectionRejectedException;

/** Which saved connection builds the account's profile. */
final readonly class ProfileConnections
{
    public const string MISSING = 'No connection can build your profile. Choose one under Settings → Profile.';
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private ConnectionPicks $connectionPicks,
        private RecommendationEngineResolver $engines,
    ) {
    }

    public function findUsableConnection(User $user): ?AiProviderSettings
    {
        return $this->findUsableConnectionGiven($user, $this->recommendationSettings->findForUser($user));
    }

    public function findUsableConnectionGiven(User $user, ?RecommendationSettings $storedSettings): ?AiProviderSettings
    {
        return $this->connectionPicks->findUsableConnectionGiven(
            $user,
            $storedSettings?->profileSettings()->connection,
            $this->canBuildProfiles(...),
        );
    }

    /**
     * @throws ConfigurationNotFoundException when the connection is not this account's
     * @throws ProfileConnectionRejectedException when it cannot build a profile
     */
    public function requireAllowedPickIfSet(
        User $user,
        ?RecommendationSettings $storedSettings,
        ?int $connectionId,
    ): ?AiProviderSettings {
        try {
            return $this->connectionPicks->requireAllowedPickIfSet(
                $user,
                $connectionId,
                $storedSettings?->profileSettings()->connection,
                $this->canBuildProfiles(...),
            );
        } catch (ConnectionPickRejectedException $exception) {
            throw new ProfileConnectionRejectedException(self::REJECTION, previous: $exception);
        }
    }

    public function canBuildProfiles(AiProviderSettings $connection): bool
    {
        return AiReadiness::isReady($connection)
            && RecommendationProfileSource::Own === $this->engines->buildCapabilities($connection)->profileSource;
    }

    /** @return list<AiProviderSettings> */
    public function findCandidates(User $user): array
    {
        return $this->connectionPicks->findCandidates($user, $this->canBuildProfiles(...));
    }
}
