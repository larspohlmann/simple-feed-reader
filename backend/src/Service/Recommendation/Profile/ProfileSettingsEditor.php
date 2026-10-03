<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Recommendation\Exception\ProfileConnectionRejectedException;
use App\Service\Recommendation\Profile\Model\ProfileSettingsChangeModel;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;

final readonly class ProfileSettingsEditor
{
    public const string REJECTION = 'Only a ready LLM connection can build your profile.';

    public function __construct(
        private AiConfigurationForUser $configurations,
        private ProfileConnections $profileConnections,
        private RecommendationSettingsWriter $writer,
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
            $this->connectionFor($user, $change->connectionId),
            $change->keptCap,
            $change->viewedCap,
        ));
    }

    private function connectionFor(User $user, ?int $connectionId): ?AiProviderSettings
    {
        if (null === $connectionId) {
            return null;
        }

        $connection = $this->configurations->require($user, $connectionId);
        if (!$this->profileConnections->canBuildProfiles($connection)) {
            throw new ProfileConnectionRejectedException(self::REJECTION);
        }

        return $connection;
    }
}
