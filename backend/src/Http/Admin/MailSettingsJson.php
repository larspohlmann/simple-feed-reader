<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\MailServerSettings;
use App\Service\Mail\Settings\MailFallbackContext;

/**
 * The admin mail payload. The password is absent by construction: only the
 * 4-char hint and a hasPassword flag cross the wire, never the secret. With no
 * row yet, the non-secret fields are seeded from the env fallback so the form
 * shows what is currently active; the password is never seeded from the env.
 */
final readonly class MailSettingsJson
{
    /**
     * @return array{
     *     enabled: bool, host: string, port: int, username: string|null,
     *     encryption: string, fromAddress: string, fromName: string,
     *     hasPassword: bool, passwordHint: string,
     *     hasSavedConfig: bool, envFallbackConfigured: bool,
     * }
     */
    public static function from(?MailServerSettings $settings, MailFallbackContext $fallback): array
    {
        if (null === $settings) {
            return [
                'enabled' => $fallback->isReal,
                'host' => $fallback->host,
                'port' => $fallback->port,
                'username' => $fallback->username,
                'encryption' => $fallback->encryption->value,
                'fromAddress' => $fallback->fromAddress,
                'fromName' => $fallback->fromName,
                'hasPassword' => false,
                'passwordHint' => '',
                'hasSavedConfig' => false,
                'envFallbackConfigured' => $fallback->isReal,
            ];
        }

        return [
            'enabled' => $settings->isEnabled(),
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'username' => $settings->getUsername(),
            'encryption' => $settings->getEncryption()->value,
            'fromAddress' => $settings->getFromAddress(),
            'fromName' => $settings->getFromName(),
            'hasPassword' => $settings->hasPassword(),
            'passwordHint' => $settings->getPasswordHint(),
            'hasSavedConfig' => true,
            'envFallbackConfigured' => $fallback->isReal,
        ];
    }
}
