<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Account\Model\AccountSettingsModel;

/**
 * The client's view of its own account. Deliberately hand-built rather than
 * serialised from the entity, so a column added later (a password hash, a
 * token, an internal flag) cannot leak into the response by default.
 */
final class MeJson
{
    /**
     * @return array<string, mixed>
     */
    public static function profile(User $user, bool $mailEnabled): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus()->value,
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'locale' => $user->getLocale(),
            'trialEndsAt' => TrialEndJson::of($user),
            'mail' => ['enabled' => $mailEnabled],
            'emailVerified' => $user->isEmailVerified(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function preferences(
        AccountSettingsModel $settings,
        bool $passkeyOfferAnswered,
        string $instanceTimezone,
    ): array {
        return [
            'scrapeFallbackEnabled' => $settings->reading->isScrapeFallbackEnabled(),
            'magazineStyle' => $settings->reading->getMagazineStyle()->value,
            'handedness' => $settings->reading->getHandedness()->value,
            'passkeyOfferAnswered' => $passkeyOfferAnswered,
            'trendingEnabled' => $settings->reading->isTrendingEnabled(),
            'feedDirectoryScope' => [
                'countries' => $settings->feedDirectoryScope->getCountries(),
                'languages' => $settings->feedDirectoryScope->getLanguages(),
            ],
            'sharing' => [
                'enabled' => $settings->sharing->isEnabled(),
                'hiddenTargets' => $settings->sharing->getHiddenTargets(),
                'rememberShares' => $settings->sharing->isRememberingShares(),
            ],
            'digest' => [
                'enabled' => $settings->digest->isEnabled(),
                'cadence' => $settings->digest->getCadence()->value,
                'format' => $settings->digest->getFormat()->value,
                'sendHour' => $settings->digest->getSendHour(),
                'weekday' => $settings->digest->getWeekday(),
                'timezone' => $instanceTimezone,
            ],
        ];
    }
}
