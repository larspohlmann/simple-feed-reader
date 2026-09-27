<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Proxy\ProxySettingsSnapshot;

/**
 * The admin proxy payload. The password is absent by construction: only a
 * hasPassword flag crosses the wire, never the secret.
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
     * }
     */
    public static function from(ProxySettingsSnapshot $settings): array
    {
        $connection = $settings->connection;

        return [
            'enabled' => $connection->enabled,
            'directFallback' => $connection->directFallback,
            'type' => $connection->type->value,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'remoteDns' => $connection->remoteDns,
            'hasPassword' => $settings->hasPassword,
        ];
    }
}
