<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Grafana\Model\GrafanaSettingsOverviewModel;

/**
 * The token is absent by construction: only hasToken and the last-four tokenHint cross the wire, never the secret.
 * Each URL carries its stored override, the env default, and the effective value the server uses now.
 */
final readonly class GrafanaSettingsJson
{
    /**
     * @return array{
     *     lokiPushUrl: string|null,
     *     lokiPushUrlDefault: string,
     *     lokiPushUrlEffective: string|null,
     *     lokiUsername: string|null,
     *     grafanaUrl: string|null,
     *     grafanaUrlDefault: string,
     *     grafanaUrlEffective: string|null,
     *     hasToken: bool,
     *     tokenHint: string,
     *     containerPresent: bool,
     *     pyroscopePushUrl: string|null,
     *     pyroscopePushUrlDefault: string,
     *     pyroscopePushUrlEffective: string|null,
     *     profilingEnabled: bool,
     *     profilingContainerPresent: bool,
     *     profilerAvailable: bool,
     * }
     */
    public static function from(GrafanaSettingsOverviewModel $overview): array
    {
        $stored = $overview->stored;
        $connection = $stored->connection;
        $defaults = $overview->defaults;

        return [
            'lokiPushUrl' => $connection->lokiPushUrl,
            'lokiPushUrlDefault' => $defaults->lokiPushUrl,
            'lokiPushUrlEffective' => self::effective($connection->lokiPushUrl, $defaults->lokiPushUrl),
            'lokiUsername' => $connection->lokiUsername,
            'grafanaUrl' => $connection->grafanaUrl,
            'grafanaUrlDefault' => $defaults->grafanaUrl,
            'grafanaUrlEffective' => self::effective($connection->grafanaUrl, $defaults->grafanaUrl),
            'hasToken' => $stored->hasToken(),
            'tokenHint' => $stored->tokenHint,
            'containerPresent' => '' !== $defaults->lokiPushUrl,
            'pyroscopePushUrl' => $connection->pyroscopePushUrl,
            'pyroscopePushUrlDefault' => $defaults->pyroscopePushUrl,
            'pyroscopePushUrlEffective' => self::effective($connection->pyroscopePushUrl, $defaults->pyroscopePushUrl),
            'profilingEnabled' => $connection->profilingEnabled,
            'profilingContainerPresent' => '' !== $defaults->pyroscopePushUrl,
            'profilerAvailable' => $overview->profilerAvailable,
        ];
    }

    private static function effective(?string $override, string $default): ?string
    {
        if (null !== $override) {
            return $override;
        }

        return '' === $default ? null : $default;
    }
}
