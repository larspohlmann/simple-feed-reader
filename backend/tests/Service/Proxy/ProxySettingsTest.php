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
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: 'user',
            password: 'sw0rdfish',
        ));

        $view = $settings->view();
        self::assertTrue($view['enabled']);
        self::assertTrue($view['directFallback']);
        self::assertSame('SOCKS5', $view['type']);
        self::assertSame('proxy.example', $view['host']);
        self::assertSame(1080, $view['port']);
        self::assertSame('user', $view['username']);
        self::assertTrue($view['hasPassword']);
        self::assertSame('fish', $view['passwordHint']);
        self::assertArrayNotHasKey('password', $view);
    }

    /**
     * A byte-wise substr() would cut a multibyte password mid-codepoint, and the
     * malformed hint that produced could not be JSON-encoded — so the row saved
     * but every later GET of the admin payload threw on it.
     */
    public function testAMultibytePasswordYieldsAHintThatSurvivesJsonEncoding(): void
    {
        $settings = $this->service($stored);

        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: null,
            password: 'a😀b',
        ));

        $view = $settings->view();
        self::assertSame('a😀b', $view['passwordHint']);
        self::assertTrue(mb_check_encoding($view['passwordHint'], 'UTF-8'));
        // The original defect: json_encode() returned false on the malformed
        // hint, so JsonResponse threw and the admin payload could not be sent.
        self::assertNotFalse(json_encode($view));
    }

    /**
     * The DNS switch decides which SOCKS scheme the fetchers use, so it has to
     * survive the round trip through the row and reach ProxyConfig (#490).
     */
    public function testRemoteDnsRoundTripsThroughTheRowIntoTheProxyConfig(): void
    {
        $settings = $this->service($stored);

        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: null,
            remoteDns: true,
            password: 'pw',
        ));

        self::assertTrue($settings->view()['remoteDns']);
        self::assertSame('socks5h://proxy.example:1080', $settings->configuredProxy()?->dsn());
    }

    public function testLocalDnsIsTheDefaultForAFreshInstance(): void
    {
        $settings = $this->service($stored);

        self::assertFalse($settings->view()['remoteDns']);

        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: null,
            password: 'pw',
        ));

        self::assertSame('socks5://proxy.example:1080', $settings->configuredProxy()?->dsn());
    }

    public function testBlankPasswordKeepsTheStoredSecret(): void
    {
        $settings = $this->service($stored);
        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'a',
            port: 1,
            username: null,
            password: 'sw0rdfish',
        ));

        $settings->update(new ProxySettingsRequest(
            enabled: false,
            directFallback: false,
            type: 'HTTP',
            host: 'b',
            port: 2,
            username: null,
            password: null,
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
            enabled: false,
            directFallback: true,
            type: 'SOCKS5',
            host: 'a',
            port: 1,
            username: null,
            password: 'pw123456',
        ));

        self::assertNull($settings->egressProxy());
        self::assertNotNull($settings->configuredProxy());
    }

    public function testConfiguredProxyIsNullWhenNeverConfigured(): void
    {
        self::assertNull($this->service($stored)->configuredProxy());
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(new ProxyServerSettings());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $settings = new ProxySettings($repository, $em, new ProxyPasswordCipher(self::SECRET));

        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: null,
            password: 'pw123456',
        ));
    }

    public function testDirectFallbackSurvivesTheRoundTrip(): void
    {
        $settings = $this->service($stored);
        $settings->update(new ProxySettingsRequest(
            enabled: true,
            directFallback: false,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: null,
            password: 'pw123456',
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
