<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\GrafanaSettings;

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
     * }
     */
    public static function from(?GrafanaSettings $settings, string $lokiPushUrlDefault, string $grafanaUrlDefault): array
    {
        $settings ??= new GrafanaSettings();
        $lokiOverride = $settings->getLokiPushUrlOverride();
        $grafanaOverride = $settings->getGrafanaUrlOverride();

        return [
            'lokiPushUrl' => $lokiOverride,
            'lokiPushUrlDefault' => $lokiPushUrlDefault,
            'lokiPushUrlEffective' => self::effective($lokiOverride, $lokiPushUrlDefault),
            'lokiUsername' => $settings->getLokiUsername(),
            'grafanaUrl' => $grafanaOverride,
            'grafanaUrlDefault' => $grafanaUrlDefault,
            'grafanaUrlEffective' => self::effective($grafanaOverride, $grafanaUrlDefault),
            'hasToken' => $settings->hasToken(),
            'tokenHint' => $settings->getTokenHint(),
            'containerPresent' => '' !== $lokiPushUrlDefault,
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
