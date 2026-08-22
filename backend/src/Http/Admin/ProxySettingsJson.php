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
     *     hasPassword: bool,
     *     passwordHint: string,
     * }
     */
    public static function from(?ProxyServerSettings $settings): array
    {
        if (null === $settings) {
            return [
                'enabled' => false,
                'directFallback' => true,
                'type' => 'SOCKS5',
                'host' => '',
                'port' => 1080,
                'username' => null,
                'hasPassword' => false,
                'passwordHint' => '',
            ];
        }

        return [
            'enabled' => $settings->isEnabled(),
            'directFallback' => $settings->isDirectFallback(),
            'type' => $settings->getType()->value,
            'host' => $settings->getHost(),
            'port' => $settings->getPort(),
            'username' => $settings->getUsername(),
            'hasPassword' => $settings->hasPassword(),
            'passwordHint' => $settings->getPasswordHint(),
        ];
    }
}
