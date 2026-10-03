<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileTuning;
use App\Service\Recommendation\Profile\Model\ProfileSettingsModel;
use App\Service\Recommendation\Profile\Support\ProfileSchedule;
use App\Service\Recommendation\Settings\Support\RecommendationSettingsBounds;

/** The profile section's state. `connection` is the one that builds the profile; null means none can. */
final class ProfileSettingsJson
{
    /** @return array<string, mixed> */
    public static function state(ProfileSettingsModel $profile): array
    {
        $stored = $profile->storedProfile;

        return [
            'profileText' => $stored->getText(),
            'generatedAt' => $stored->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'generatedBy' => null === $stored->getModel()
                ? null
                : ['providerHost' => $stored->getProviderHost(), 'model' => $stored->getModel()],
            'intervalHours' => $profile->values->intervalHours,
            'intervalChoices' => ProfileSchedule::INTERVAL_CHOICES,
            'connectionId' => $profile->values->connection?->getId(),
            'connection' => null === $profile->effectiveConnection
                ? null
                : self::connection($profile->effectiveConnection),
            'candidates' => array_map(self::connection(...), $profile->candidates),
            'keptCap' => $profile->values->keptCap,
            'viewedCap' => $profile->values->viewedCap,
            'defaults' => [
                'keptCap' => ProfileTuning::DEFAULT_KEPT_CAP,
                'viewedCap' => ProfileTuning::DEFAULT_VIEWED_CAP,
            ],
            'bounds' => RecommendationSettingsBounds::PROFILE_FIELDS,
            'debugEnabled' => $profile->debugEnabled,
        ];
    }

    /** @return array{id: ?int, name: ?string, baseUrl: string, model: ?string} */
    private static function connection(AiProviderSettings $connection): array
    {
        return [
            'id' => $connection->getId(),
            'name' => $connection->getName(),
            'baseUrl' => $connection->getBaseUrl(),
            'model' => $connection->getModel(),
        ];
    }

    private function __construct()
    {
    }
}
