<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProxySettingsTest extends TestCase
{
    private const SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public function testUpdateThenViewHidesSecretAndKeepsHint(): void
    {
        $settings = $this->service($stored);

        $settings->update(new ProxySettingsRequest(
            enabled: true, directFallback: true, type: 'SOCKS5',
            host: 'proxy.example', port: 1080, username: 'user', password: 'sw0rdfish',
        ));

        $view = $settings->view();
        self::assertTrue($view['enabled']);
        self::assertTrue($view['directFallback']);
        self::assertSame('SOCKS5', $view['type']);
        self::assertSame('proxy.example', $view['host']);
        self::assertTrue($view['hasPassword']);
        self::assertSame('fish', $view['passwordHint']);
        self::assertArrayNotHasKey('password', $view);
    }

    public function testBlankPasswordKeepsTheStoredSecret(): void
    {
        $settings = $this->service($stored);
        $settings->update(new ProxySettingsRequest(
            enabled: true, directFallback: true, type: 'SOCKS5',
            host: 'a', port: 1, username: null, password: 'sw0rdfish',
        ));

        $settings->update(new ProxySettingsRequest(
            enabled: false, directFallback: false, type: 'HTTP',
            host: 'b', port: 2, username: null, password: null,
        ));

        $egress = $settings->configuredProxy();
        self::assertNotNull($egress);
        self::assertSame(ProxyType::Http, $egress->type);
        self::assertSame('sw0rdfish', $egress->password);
        self::assertFalse($egress->directFallback);
    }

    public function testEgressProxyIsNullWhenDisabled(): void
    {
        $settings = $this->service($stored);
        $settings->update(new ProxySettingsRequest(
            enabled: false, directFallback: true, type: 'SOCKS5',
            host: 'a', port: 1, username: null, password: 'pw123456',
        ));

        self::assertNull($settings->egressProxy());
        self::assertNotNull($settings->configuredProxy());
    }

    public function testConfiguredProxyIsNullWhenNeverConfigured(): void
    {
        self::assertNull($this->service($stored)->configuredProxy());
    }

    public function testDirectFallbackSurvivesTheRoundTrip(): void
    {
        $settings = $this->service($stored);
        $settings->update(new ProxySettingsRequest(
            enabled: true, directFallback: false, type: 'SOCKS5',
            host: 'proxy.example', port: 1080, username: null, password: 'pw123456',
        ));

        $egress = $settings->configuredProxy();
        self::assertNotNull($egress);
        self::assertFalse($egress->directFallback);
    }

    /** @param ProxyServerSettings|null $stored captured by reference for the fake repo. */
    private function service(?ProxyServerSettings &$stored): ProxySettings
    {
        $stored = null;
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$stored): void {
            if ($entity instanceof ProxyServerSettings) {
                $stored = $entity;
            }
        });

        return new ProxySettings($repository, $em, new ProxyPasswordCipher(self::SECRET));
    }
}
