<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Profile;

use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Recommendation\Profile\Model\ProfileSettingsModel;

final readonly class ProfileSettingsProvider
{
    public function __construct(
        private RecommendationSettingsRepository $recommendationSettings,
        private ProfileConnections $profileConnections,
    ) {
    }

    public function forUser(User $user): ProfileSettingsModel
    {
        $storedSettings = $this->recommendationSettings->findForUser($user);

        return new ProfileSettingsModel(
            $storedSettings?->getStoredProfile() ?? StoredProfile::none(),
            $storedSettings?->profileSettings() ?? ProfileSettingsValues::defaults(),
            $this->profileConnections->findUsableConnectionGiven($user, $storedSettings),
            $this->profileConnections->findCandidates($user),
            $storedSettings?->values()->debugEnabled ?? false,
        );
    }
}
