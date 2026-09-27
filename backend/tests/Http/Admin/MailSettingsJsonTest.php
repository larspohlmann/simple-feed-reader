<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Http\Admin\MailSettingsJson;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsOverview;
use App\Service\Mail\Settings\MailSettingsSnapshot;
use App\Service\Proxy\ProxyConnection;
use PHPUnit\Framework\TestCase;

final class MailSettingsJsonTest extends TestCase
{
    public function testWithNoRowThePayloadIsSeededFromTheEnvFallbackWithoutAPassword(): void
    {
        $fallback = new MailConnection(true, 'smtp.env.test', 2525, 'env-user', MailEncryption::Tls, 'a@env', 'Env');

        self::assertSame([
            'enabled' => true,
            'host' => 'smtp.env.test',
            'port' => 2525,
            'username' => 'env-user',
            'encryption' => 'tls',
            'fromAddress' => 'a@env',
            'fromName' => 'Env',
            'hasPassword' => false,
            'hasSavedConfig' => false,
            'envFallbackConfigured' => true,
            'useProxy' => false,
            'proxyConfigured' => false,
            'proxyLabel' => '',
        ], MailSettingsJson::from(new MailSettingsOverview(null, $fallback, null)));
    }

    public function testProxyAvailabilityIsExposedWhenAProxyIsConfigured(): void
    {
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');
        $proxy = new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null, true);

        $payload = MailSettingsJson::from(new MailSettingsOverview(null, $fallback, $proxy));

        self::assertTrue($payload['proxyConfigured']);
        self::assertSame('SOCKS5 · proxy.example:1080', $payload['proxyLabel']);
        self::assertFalse($payload['useProxy']);
    }

    public function testWithARowThePayloadIsTheRowPlusTheFallbackFlag(): void
    {
        $saved = new MailSettingsSnapshot(
            new MailConnection(false, 'smtp.row.test', 465, null, MailEncryption::None, 'a@row', 'Row'),
            true,
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');

        $payload = MailSettingsJson::from(new MailSettingsOverview($saved, $fallback, null));

        self::assertArrayNotHasKey('passwordHint', $payload);
        self::assertSame([
            'enabled' => false,
            'host' => 'smtp.row.test',
            'port' => 465,
            'username' => null,
            'encryption' => 'none',
            'fromAddress' => 'a@row',
            'fromName' => 'Row',
            'hasPassword' => true,
            'hasSavedConfig' => true,
            'envFallbackConfigured' => false,
            'useProxy' => false,
            'proxyConfigured' => false,
            'proxyLabel' => '',
        ], $payload);
    }

    public function testASavedRowThatRoutesThroughTheProxySaysSo(): void
    {
        $saved = new MailSettingsSnapshot(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, 'a@row', 'Row', true),
            false,
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');
        $proxy = new ProxyConnection(false, true, ProxyType::Http, 'proxy.example', 3128, null);

        $payload = MailSettingsJson::from(new MailSettingsOverview($saved, $fallback, $proxy));

        self::assertTrue($payload['useProxy']);
        self::assertFalse($payload['hasPassword']);
        self::assertTrue($payload['proxyConfigured']);
        self::assertSame('HTTP · proxy.example:3128', $payload['proxyLabel']);
    }
}
