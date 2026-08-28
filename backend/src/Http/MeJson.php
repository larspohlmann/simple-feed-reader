<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;

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
    public static function profile(User $user, bool $mailEnabled, string $instanceTimezone): array
    {
        $aiSettings = $user->getActiveAiProviderSettings();
        $preferences = $user->getPreferences();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus()->value,
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'locale' => $user->getLocale(),
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
            'mail' => ['enabled' => $mailEnabled],
            'emailVerified' => $user->isEmailVerified(),
            'preferences' => [
                'scrapeFallbackEnabled' => $preferences->isScrapeFallbackEnabled(),
                'digest' => [
                    'enabled' => $preferences->isDigestEnabled(),
                    'cadence' => $preferences->getDigestCadence()->value,
                    'sendHour' => $preferences->getDigestSendHour(),
                    'weekday' => $preferences->getDigestWeekday(),
                    'timezone' => $instanceTimezone,
                ],
            ],
            'ai' => [
                'ready' => AiSettingsJson::isReady($aiSettings),
                'model' => $aiSettings?->getModel(),
            ],
        ];
    }
}
