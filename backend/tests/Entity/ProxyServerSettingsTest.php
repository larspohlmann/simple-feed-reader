<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Proxy\Crypto\SealedProxyPassword;
use App\Service\Proxy\ProxyConnection;
use PHPUnit\Framework\TestCase;

final class ProxyServerSettingsTest extends TestCase
{
    public function testApplyStoresConnectionAndSealedPassword(): void
    {
        $settings = new ProxyServerSettings();
        $sealed = new SealedProxyPassword('cipher', 'nonce', 'salt', 1);

        $settings->apply(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            $sealed,
            'word',
        );

        self::assertTrue($settings->isEnabled());
        self::assertTrue($settings->isDirectFallback());
        self::assertSame(ProxyType::Socks5, $settings->getType());
        self::assertSame('proxy.example', $settings->getHost());
        self::assertSame(1080, $settings->getPort());
        self::assertSame('user', $settings->getUsername());
        self::assertSame('word', $settings->getPasswordHint());
        self::assertTrue($settings->hasPassword());
        self::assertEquals($sealed, $settings->getSealedPassword());
    }

    public function testApplyWithoutPasswordKeepsTheStoredSecret(): void
    {
        $settings = new ProxyServerSettings();
        $sealed = new SealedProxyPassword('cipher', 'nonce', 'salt', 1);
        $settings->apply(new ProxyConnection(true, true, ProxyType::Http, 'a', 1, 'u'), $sealed, 'word');

        $settings->applyWithoutPassword(new ProxyConnection(false, false, ProxyType::Socks5, 'b', 2, null));

        self::assertFalse($settings->isEnabled());
        self::assertFalse($settings->isDirectFallback());
        self::assertSame(ProxyType::Socks5, $settings->getType());
        self::assertSame('b', $settings->getHost());
        self::assertNull($settings->getUsername());
        self::assertEquals($sealed, $settings->getSealedPassword());
        self::assertSame('word', $settings->getPasswordHint());
    }
}
