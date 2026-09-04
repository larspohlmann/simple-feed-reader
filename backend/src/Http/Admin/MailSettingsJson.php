<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\MailServerSettings;
use App\Service\Mail\Settings\MailConnection;

/**
 * The admin mail payload. The password never crosses the wire — only a
 * hasPassword flag does. With no row yet, non-secret fields seed from the env
 * fallback (never the password) so the form shows what is currently active.
 *
 * @phpstan-type MailSettingsPayload array{
 *     enabled: bool, host: string, port: int, username: string|null,
 *     encryption: string, fromAddress: string, fromName: string,
 *     hasPassword: bool,
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
            'hasSavedConfig' => null !== $settings,
            'envFallbackConfigured' => $fallback->enabled,
        ];
    }
}
