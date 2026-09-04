<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
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
    private const string ROTATED_SECRET = 'a-DIFFERENT-master-secret-at-least-32-chars!';

    public function testReturnsEgressIpOnSuccessAndRoutesThroughTheProxy(): void
    {
        $seenProxy = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$seenProxy): MockResponse {
                $seenProxy = $options['proxy'] ?? null;

                return new MockResponse('203.0.113.7');
            }
        );
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertSame('socks5://user:pw@proxy.example:1080', $seenProxy);
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

    public function testRequestDisablesRedirectsAndAsksForPlainText(): void
    {
        $seenOptions = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions = $options;

                return new MockResponse('203.0.113.7');
            }
        );
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $tester->test();

        self::assertIsArray($seenOptions);
        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
        $headers = $seenOptions['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertContains('Accept: text/plain', $headers);
    }

    public function testHttpStatusOutsideTheSuccessRangeIsReportedByCode(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('nope', ['http_code' => 404]);
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame('HTTP 404', $result->reason);
    }

    public function testAStatusOfExactlyThreeHundredIsAlreadyAFailure(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('nope', ['http_code' => 300]);
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame('HTTP 300', $result->reason);
    }

    public function testEgressIpIsTruncatedToTheByteCapBeforeTrimming(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(str_repeat('9', 2000) . "\n");
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame(str_repeat('9', 1024), $result->egressIp);
    }

    public function testEgressIpHasSurroundingWhitespaceTrimmed(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(" 203.0.113.7 \n");
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
    }

    /**
     * Diagnosing exactly this is what the Test button is for: the row was sealed
     * under one master secret and is being read under another (a rotated
     * INSTANCE_SECRET_KEY, or a dump restored onto a fresh instance), so it reports
     * the unreadable secret rather than crashing the endpoint.
     */
    public function testAnUnreadableStoredPasswordIsReportedRatherThanThrown(): void
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

        (new ProxySettings($repository, $em, new ProxyPasswordCipher(new InstanceSecretCipher(self::SECRET))))->update(
            new ProxySettingsRequest(
                enabled: true,
                directFallback: true,
                type: 'SOCKS5',
                host: 'proxy.example',
                port: 1080,
                username: 'user',
                password: 'pw',
            ),
        );

        $rotatedCipher = new ProxyPasswordCipher(new InstanceSecretCipher(self::ROTATED_SECRET));
        $afterRotation = new ProxySettings($repository, $em, $rotatedCipher);
        $result = (new ProxyConnectionTester($afterRotation, new MockHttpClient()))->test();

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertNotNull($result->reason);
    }

    /**
     * The reported defect: the page showed curl's raw RFC 1928 reply byte, so
     * the admin saw "(4)" where a reason belonged.
     */
    public function testASocks5HandshakeRefusalIsExplainedNotJustNumbered(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', [
                'error' => 'cannot complete SOCKS5 connection to api.ipify.org. (4)',
            ]);
        });
        $tester = new ProxyConnectionTester($this->configuredSettings(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertIsString($result->reason);
        self::assertStringContainsString('does not resolve host names', $result->reason);
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

        return new ProxySettings($repository, $em, new ProxyPasswordCipher(new InstanceSecretCipher(self::SECRET)));
    }
}
