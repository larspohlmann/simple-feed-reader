<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxyConnectionTester;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * ProxySettings is `final readonly` and cannot be doubled by PHPUnit — its own
 * test suite (ProxySettingsTest) works around this by driving a real instance
 * through a stubbed repository, and this test follows the same pattern rather
 * than mocking ProxySettings directly.
 */
final class ProxyConnectionTesterTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public function testReturnsEgressIpOnSuccessAndRoutesThroughTheProxy(): void
    {
        $seenProxy = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenProxy): MockResponse {
            $seenProxy = $options['proxy'] ?? null;

            return new MockResponse('203.0.113.7');
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertSame('socks5h://user:pw@proxy.example:1080', $seenProxy);
    }

    public function testReturnsNotConfiguredWhenNoProxyStored(): void
    {
        $tester = new ProxyConnectionTester($this->unconfiguredSettings(), new MockHttpClient());

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }

    public function testMapsTransportFailureToAReason(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'Failed to connect via proxy']);
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }

    private function configuredSettings(): ProxySettings
    {
        $settings = $this->settings();
        $settings->update(new ProxySettingsRequest(
            enabled: false,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: 'user',
            password: 'pw',
        ));

        return $settings;
    }

    private function unconfiguredSettings(): ProxySettings
    {
        return $this->settings();
    }

    private function settings(): ProxySettings
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
