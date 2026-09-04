<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\MailServerSettings;
use App\Service\Mail\Settings\MailConnection;

/**
 * The admin mail payload. The password is absent by construction: only the
 * 4-char hint and a hasPassword flag cross the wire, never the secret. With no
 * row yet, the non-secret fields are seeded from the env fallback so the form
 * shows what is currently active; the password is never seeded from the env.
 *
 * @phpstan-type MailSettingsPayload array{
 *     enabled: bool, host: string, port: int, username: string|null,
 *     encryption: string, fromAddress: string, fromName: string,
 *     hasPassword: bool, passwordHint: string,
 *     hasSavedConfig: bool, envFallbackConfigured: bool,
 * }
 */
final readonly class MailSettingsJson
{
    /** @return MailSettingsPayload */
    public static function from(?MailServerSettings $settings, MailConnection $fallback): array
    {
        $connection = $settings?->connection() ?? $fallback;

        return [
            'enabled' => $connection->enabled,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'encryption' => $connection->encryption->value,
            'fromAddress' => $connection->fromAddress,
            'fromName' => $connection->fromName,
            'hasPassword' => $settings?->hasPassword() ?? false,
            'passwordHint' => $settings?->getPasswordHint() ?? '',
            'hasSavedConfig' => null !== $settings,
            'envFallbackConfigured' => $fallback->enabled,
        ];
    }
}
