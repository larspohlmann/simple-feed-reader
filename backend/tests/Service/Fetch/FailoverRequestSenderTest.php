<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\ProxyEgressResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class FailoverRequestSenderTest extends TestCase
{
    private const array DUAL_STACK = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'];

    private function sender(MockHttpClient $client): FailoverRequestSender
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return new FailoverRequestSender($client, $resolver);
    }

    public function testFailsOverToTheNextFamilyWhenTheFirstConnectsButDiesBeforeHeaders(): void
    {
        // The both-families pin leads with the IPv6 address; a route that resets
        // at the TLS handshake (heise's IPv6 from Strato) is a transport error
        // the client cannot recover from on its own.
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $pin = $resolve['dual.example.com'];

            return str_contains($pin, ':')
                ? new MockResponse('', ['error' => 'Connection reset by peer'])
                : new MockResponse('served over IPv4', ['http_code' => 200]);
        });

        $response = $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('served over IPv4', $response->getContent());
    }

    public function testReturnsTheFirstResponseWhenItArrives(): void
    {
        $client = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);

        $response = $this->sender($client)
            ->send('GET', 'https://example.com/post', new GuardedUrl('example.com', ['93.184.216.34']), []);

        self::assertSame('ok', $response->getContent());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testFailsOverToTheNextFamilyOnAnErrorStatus(): void
    {
        // taz.de forbids its IPv6 range from Strato (403) while IPv4 serves 200;
        // the error status must fall over to the family that answers.
        $client = new MockHttpClient([
            new MockResponse('forbidden over IPv6', ['http_code' => 403]),
            new MockResponse('served over IPv4', ['http_code' => 200]),
        ]);

        $response = $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame('served over IPv4', $response->getContent());
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testWalksEveryFamilyUntilOneAnswers(): void
    {
        // Both the combined pin and the IPv4-only pin reset; only the IPv6-only
        // pin answers. The sender must try the whole attempt list, not stop after
        // the first fallback.
        [$ipv6] = self::DUAL_STACK;
        $onlyIpv6Answers = static function (string $method, string $url, array $options) use ($ipv6): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return $resolve['dual.example.com'] === $ipv6
                ? new MockResponse('answered over IPv6', ['http_code' => 200])
                : new MockResponse('', ['error' => 'Connection reset by peer']);
        };
        $client = new MockHttpClient($onlyIpv6Answers);

        $response = $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame('answered over IPv6', $response->getContent());
        self::assertSame(3, $client->getRequestsCount());
    }

    public function testForcesAFreshConnectionOnAFailoverRetry(): void
    {
        // curl pools connections by host:port. The dead family's connection can be
        // alive and keep-alive (taz's IPv6 answers 403), so without a fresh
        // connection the IPv4 retry would reuse it and get 403 again. The first
        // attempt reuses the pool as normal; every retry must not.
        /** @var list<bool> $freshConnectPerAttempt */
        $freshConnectPerAttempt = [];
        $capture = function (string $method, string $url, array $options) use (&$freshConnectPerAttempt): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $extra = $options['extra'] ?? null;
            $curl = \is_array($extra) ? ($extra['curl'] ?? null) : null;
            $freshConnectPerAttempt[] = \is_array($curl) && ($curl[\CURLOPT_FRESH_CONNECT] ?? null) === true;

            return str_contains($resolve['dual.example.com'], ',')
                ? new MockResponse('forbidden', ['http_code' => 403])
                : new MockResponse('served over IPv4', ['http_code' => 200]);
        };

        $this->sender(new MockHttpClient($capture))
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame([false, true], $freshConnectPerAttempt);
    }

    public function testKeepsTheFinalFamilysErrorStatusWhenNoFamilyAnswers(): void
    {
        // Both families forbid the request: the last answer stands so the caller
        // still sees the real 403 rather than a synthesised failure.
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('nope', ['http_code' => 403]));

        $response = $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReturnsARedirectForTheCallerToFollowRatherThanFailingOver(): void
    {
        // A 3xx is not an error to route around; the caller follows it. The sender
        // must return it from the first family without trying another.
        $redirect = new MockResponse('', [
            'http_code' => 301,
            'response_headers' => ['location' => ['https://dual.example.com/moved']],
        ]);
        $client = new MockHttpClient([$redirect, new MockResponse('unexpected second try', ['http_code' => 200])]);

        $response = $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testThrowsTheTransportErrorWhenEveryFamilyFails(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['error' => 'Network is unreachable']),
        );

        $this->expectException(TransportExceptionInterface::class);

        $this->sender($client)
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);
    }
}
