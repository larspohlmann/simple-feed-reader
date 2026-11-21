<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Profile;

use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Curation\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Curation\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Curation\Recommendation\Profile\Model\ProfileSettingsChangeModel;
use App\Service\Curation\Recommendation\Settings\RecommendationSettingsWriter;

final readonly class ProfileSettingsEditor
{
    public function __construct(
        private ProfileConnections $profileConnections,
        private RecommendationSettingsWriter $writer,
        private RecommendationSettingsRepository $recommendationSettings,
    ) {
    }

    /**
     * @throws ConfigurationNotFoundException when the connection is not this account's
     * @throws ProfileConnectionRejectedException when it cannot build a profile
     */
    public function save(User $user, ProfileSettingsChangeModel $change): void
    {
        $this->writer->saveProfileSettings($user, new ProfileSettingsValues(
            $change->intervalHours,
            $this->profileConnections->requireAllowedPickIfSet(
                $user,
                $this->recommendationSettings->findForUser($user),
                $change->connectionId,
            ),
            $change->keptCap,
            $change->viewedCap,
        ));
    }
}
