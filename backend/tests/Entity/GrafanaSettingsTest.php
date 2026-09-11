<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GrafanaSettings;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsTest extends TestCase
{
    public function testFreshEntityIsUnconfigured(): void
    {
        $settings = new GrafanaSettings();

        self::assertNull($settings->getLokiPushUrlOverride());
        self::assertNull($settings->getLokiUsername());
        self::assertNull($settings->getGrafanaUrlOverride());
        self::assertFalse($settings->hasToken());
        self::assertSame('', $settings->getTokenHint());
    }

    public function testApplyStoresOverridesAndSealedToken(): void
    {
        $settings = new GrafanaSettings();

        $settings->apply(
            new GrafanaConnection('https://loki.example/loki/api/v1/push', 'tenant42', 'https://grafana.example'),
            new SealedSecret('cipher', 'nonce', 'salt', 3),
            'wxyz',
        );

        self::assertSame('https://loki.example/loki/api/v1/push', $settings->getLokiPushUrlOverride());
        self::assertSame('tenant42', $settings->getLokiUsername());
        self::assertSame('https://grafana.example', $settings->getGrafanaUrlOverride());
        self::assertTrue($settings->hasToken());
        self::assertSame('wxyz', $settings->getTokenHint());
        self::assertEquals(new SealedSecret('cipher', 'nonce', 'salt', 3), $settings->getSealedToken());
    }

    public function testClearStoredTokenLeavesOverridesButDropsSecret(): void
    {
        $settings = new GrafanaSettings();
        $settings->apply(new GrafanaConnection('u', null, null), new SealedSecret('c', 'n', 's', 1), 'abcd');

        $settings->clearStoredToken();

        self::assertFalse($settings->hasToken());
        self::assertSame('', $settings->getTokenHint());
        self::assertSame('u', $settings->getLokiPushUrlOverride());
        self::assertEquals(new SealedSecret('', '', '', 1), $settings->getSealedToken());
    }
}
