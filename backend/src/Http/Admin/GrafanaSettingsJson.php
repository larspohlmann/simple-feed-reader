<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\GrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;

/**
 * The admin Grafana payload. The token is absent by construction: only hasToken
 * and the last-four tokenHint cross the wire, never the secret. Each URL carries
 * its stored override, the env default, and the effective value the server uses
 * now, so an admin who leaves a field empty still sees the local-container value.
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
    public static function from(
        ?GrafanaSettings $settings,
        GrafanaEnvDefaults $defaults,
        bool $profilerAvailable,
    ): array {
        $settings ??= new GrafanaSettings();
        $lokiOverride = $settings->getLokiPushUrlOverride();
        $grafanaOverride = $settings->getGrafanaUrlOverride();
        $pyroscopeOverride = $settings->getPyroscopePushUrlOverride();

        return [
            'lokiPushUrl' => $lokiOverride,
            'lokiPushUrlDefault' => $defaults->lokiPushUrl,
            'lokiPushUrlEffective' => self::effective($lokiOverride, $defaults->lokiPushUrl),
            'lokiUsername' => $settings->getLokiUsername(),
            'grafanaUrl' => $grafanaOverride,
            'grafanaUrlDefault' => $defaults->grafanaUrl,
            'grafanaUrlEffective' => self::effective($grafanaOverride, $defaults->grafanaUrl),
            'hasToken' => $settings->hasToken(),
            'tokenHint' => $settings->getTokenHint(),
            'containerPresent' => '' !== $defaults->lokiPushUrl,
            'pyroscopePushUrl' => $pyroscopeOverride,
            'pyroscopePushUrlDefault' => $defaults->pyroscopePushUrl,
            'pyroscopePushUrlEffective' => self::effective($pyroscopeOverride, $defaults->pyroscopePushUrl),
            'profilingEnabled' => $settings->isProfilingEnabled(),
            'profilingContainerPresent' => '' !== $defaults->pyroscopePushUrl,
            'profilerAvailable' => $profilerAvailable,
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
