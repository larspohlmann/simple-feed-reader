<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Http\Admin\MailSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\MailConnection;
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
        ], MailSettingsJson::from(null, $fallback, null));
    }

    public function testProxyAvailabilityIsExposedWhenAProxyIsConfigured(): void
    {
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');
        $proxy = new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true);

        $payload = MailSettingsJson::from(null, $fallback, $proxy);

        self::assertTrue($payload['proxyConfigured']);
        self::assertSame('SOCKS5 · proxy.example:1080', $payload['proxyLabel']);
        self::assertFalse($payload['useProxy']);
    }

    public function testWithARowThePayloadIsTheRowPlusTheFallbackFlag(): void
    {
        $settings = new MailServerSettings();
        $settings->apply(
            new MailConnection(false, 'smtp.row.test', 465, null, MailEncryption::None, 'a@row', 'Row'),
            new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');

        $payload = MailSettingsJson::from($settings, $fallback, null);

        self::assertArrayNotHasKey('passwordHint', $payload);
        self::assertTrue($payload['hasPassword']);
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
}
