<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyConnection;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxyConnectionTester;
use App\Service\Proxy\ProxyTestFailure;
use App\Service\Proxy\StoredProxy;
use App\Tests\Support\ProxyPasswordCiphers;
use App\Tests\Support\StoredProxies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxyConnectionTesterTest extends TestCase
{
    use StoredProxies;

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
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertSame('socks5://user:pw@proxy.example:1080', $seenProxy);
    }

    public function testReturnsNotConfiguredWhenNoProxyStored(): void
    {
        $unconfigured = $this->storedProxy(null, ProxyPasswordCiphers::withTestSecret());
        $tester = new ProxyConnectionTester($unconfigured, new MockHttpClient());

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);
    }

    public function testMapsTransportFailureToAReason(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'Failed to connect via proxy']);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::Unreachable, $result->failure);
        self::assertNotNull($result->detail);
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
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

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
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
        self::assertSame('HTTP 404', $result->detail);
    }

    public function testAStatusOfExactlyThreeHundredIsAlreadyAFailure(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('nope', ['http_code' => 300]);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
        self::assertSame('HTTP 300', $result->detail);
    }

    public function testEgressIpIsTruncatedToTheByteCapBeforeTrimming(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(str_repeat('9', 2000) . "\n");
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame(str_repeat('9', 1024), $result->egressIp);
    }

    public function testEgressIpHasSurroundingWhitespaceTrimmed(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(" 203.0.113.7 \n");
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
    }

    /**
     * The row was sealed under one master secret and is read under another (a rotated INSTANCE_SECRET_KEY, or a
     * dump restored onto a fresh instance). Diagnosing that is what the Test button is for, so it must not throw.
     */
    public function testAnUnreadableStoredPasswordIsReportedRatherThanThrown(): void
    {
        $afterRotation = $this->storedProxy($this->configuredRow(), ProxyPasswordCiphers::under(self::ROTATED_SECRET));

        $result = (new ProxyConnectionTester($afterRotation, new MockHttpClient()))->test();

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame(ProxyTestFailure::SecretUnreadable, $result->failure);
        self::assertNotNull($result->detail);
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
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::Unreachable, $result->failure);
        self::assertStringContainsString('does not resolve host names', (string) $result->detail);
    }

    private function configuredProxy(): StoredProxy
    {
        return $this->storedProxy($this->configuredRow(), ProxyPasswordCiphers::withTestSecret());
    }

    private function configuredRow(): ProxyServerSettings
    {
        $row = new ProxyServerSettings();
        $row->apply(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            ProxyPasswordCiphers::withTestSecret()->seal('pw'),
        );

        return $row;
    }

    private function storedProxy(?ProxyServerSettings $row, ProxyPasswordCipher $cipher): StoredProxy
    {
        return $this->storedProxyOver($row, $cipher);
    }
}
