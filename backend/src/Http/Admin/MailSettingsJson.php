<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Mail\Settings\MailSettingsOverview;

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
 *     useProxy: bool, proxyConfigured: bool, proxyLabel: string,
 * }
 */
final readonly class MailSettingsJson
{
    /** @return MailSettingsPayload */
    public static function from(MailSettingsOverview $overview): array
    {
        $saved = $overview->saved;
        $fallback = $overview->fallback;
        $proxy = $overview->proxy;
        $connection = null !== $saved ? $saved->connection : $fallback;

        return [
            'enabled' => $connection->enabled,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'encryption' => $connection->encryption->value,
            'fromAddress' => $connection->fromAddress,
            'fromName' => $connection->fromName,
            'hasPassword' => null !== $saved && $saved->hasPassword,
            'hasSavedConfig' => null !== $saved,
            'envFallbackConfigured' => $fallback->enabled,
            'useProxy' => $saved?->connection->useProxy ?? false,
            'proxyConfigured' => null !== $proxy,
            'proxyLabel' => null !== $proxy
                ? \sprintf('%s · %s:%d', $proxy->type->value, $proxy->host, $proxy->port)
                : '',
        ];
    }
}
