<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;

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
            $this->profileConnections->usableGiven($user, $storedSettings),
            $this->profileConnections->candidatesFor($user),
            $storedSettings?->values()->debugEnabled ?? false,
        );
    }
}
