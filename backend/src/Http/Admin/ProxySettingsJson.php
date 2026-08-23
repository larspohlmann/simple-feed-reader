<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\ProxyServerSettings;

/**
 * The admin proxy payload. The password is absent by construction: only the
 * 4-char hint and a hasPassword flag cross the wire, never the secret.
 */
final readonly class ProxySettingsJson
{
    /**
     * @return array{
     *     enabled: bool,
     *     directFallback: bool,
     *     type: string,
     *     host: string,
     *     port: int,
     *     username: string|null,
     *     remoteDns: bool,
     *     hasPassword: bool,
     *     passwordHint: string,
     * }
     */
    public static function from(?ProxyServerSettings $settings): array
    {
        // No row yet means "not configured", which is exactly what a fresh
        // entity describes — so the defaults are read from the one place that
        // declares them rather than restated here.
        $settings ??= new ProxyServerSettings();

        return [
            'enabled' => $settings->isEnabled(),
            'directFallback' => $settings->isDirectFallback(),
            'type' => $settings->getType()->value,
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'username' => $settings->getUsername(),
            'remoteDns' => $settings->isRemoteDns(),
            'hasPassword' => $settings->hasPassword(),
            'passwordHint' => $settings->getPasswordHint(),
        ];
    }
}
