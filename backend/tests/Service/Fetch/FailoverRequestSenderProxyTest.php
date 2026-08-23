<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Proxy\Crypto\Exception\ProxyPasswordUnreadableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FailoverRequestSenderProxyTest extends TestCase
{
    public function testProxyRequestOmitsResolveAndIsTriedFirst(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$calls): MockResponse {
            $calls[] = $o;

            return new MockResponse('ok');
        });
        $sender = new FailoverRequestSender($client, $this->resolver($this->proxy()));

        $sender
            ->send('GET', 'https://page.example', $this->guarded(), ['timeout' => 7.0])
            ->getStatusCode();

        self::assertCount(1, $calls);
        self::assertSame('socks5h://p:1080', $calls[0]['proxy'] ?? null);
        self::assertSame(7.0, $calls[0]['timeout'] ?? null);
        self::assertArrayNotHasKey('resolve', $calls[0]);
    }

    public function testProxyTransportFailureFallsThroughToPinnedDirect(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$calls): MockResponse {
            $calls[] = $o;
            if (isset($o['proxy'])) {
                return new MockResponse('', ['error' => 'proxy down']);
            }

            return new MockResponse('ok');
        });
        $sender = new FailoverRequestSender($client, $this->resolver($this->proxy()));

        $status = $sender->send('GET', 'https://page.example', $this->guarded(), [])->getStatusCode();

        self::assertSame(200, $status);
        self::assertArrayHasKey('proxy', $calls[0]);
        self::assertArrayHasKey('resolve', $calls[1]);
    }

    /**
     * MockHttpClient wraps every response in a fresh object, so a canceled flag
     * set on the mock template is invisible from the test. A hand-built
     * ResponseInterface double is the only way to observe that the failed proxy
     * attempt is actually released rather than left open.
     */
    public function testProxyTransportFailureCancelsTheFailedResponse(): void
    {
        $failedResponse = $this->createMock(ResponseInterface::class);
        $failedResponse->method('getStatusCode')
            ->willThrowException(new TransportException('proxy down'));
        $failedResponse->expects(self::once())->method('cancel');

        $okResponse = $this->createStub(ResponseInterface::class);
        $okResponse->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failedResponse, $okResponse);

        $sender = new FailoverRequestSender($httpClient, $this->resolver($this->proxy()));

        $status = $sender->send('GET', 'https://page.example', $this->guarded(), [])->getStatusCode();

        self::assertSame(200, $status);
    }

    public function testNullResolverIsByteForByteTheCurrentLoop(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$calls): MockResponse {
            $calls[] = $o;

            return new MockResponse('ok');
        });
        $sender = new FailoverRequestSender($client, $this->resolver(null));

        $sender->send('GET', 'https://page.example', $this->guarded(), [])->getStatusCode();

        self::assertArrayHasKey('resolve', $calls[0]);
        self::assertArrayNotHasKey('proxy', $calls[0]);
    }

    public function testDirectFallbackDisabledThrowsInsteadOfFallingThrough(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$calls): MockResponse {
            $calls[] = $o;

            return new MockResponse('', ['error' => 'proxy down']);
        });
        $sender = new FailoverRequestSender($client, $this->resolver($this->proxy(directFallback: false)));

        $this->expectException(TransportExceptionInterface::class);

        try {
            $sender->send('GET', 'https://page.example', $this->guarded(), []);
        } finally {
            self::assertCount(1, $calls);
            self::assertArrayHasKey('proxy', $calls[0]);
            self::assertArrayNotHasKey('resolve', $calls[0]);
        }
    }

    /**
     * A proxy that is enabled but whose stored password cannot be opened must
     * not silently degrade to a direct request — that would reveal the very IP
     * the proxy exists to hide. It arrives as a transport failure instead, which
     * is what the callers already know how to report.
     */
    public function testAnUnreadableProxyPasswordFailsTheSendAndNeverGoesDirect(): void
    {
        $calls = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$calls): MockResponse {
            $calls[] = $o;

            return new MockResponse('ok');
        });
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willThrowException(
            new ProxyPasswordUnreadableException('The stored proxy password failed its integrity check.'),
        );
        $sender = new FailoverRequestSender($client, $resolver);

        $this->expectException(TransportExceptionInterface::class);

        try {
            $sender->send('GET', 'https://page.example', $this->guarded(), []);
        } finally {
            self::assertSame([], $calls, 'no request may be sent, least of all a direct one');
        }
    }

    private function resolver(?ProxyConfig $config): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn($config);

        return $resolver;
    }

    private function proxy(bool $directFallback = true): ProxyConfig
    {
        return new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null, $directFallback);
    }

    private function guarded(): GuardedUrl
    {
        return new GuardedUrl('page.example', ['203.0.113.9']);
    }
}
