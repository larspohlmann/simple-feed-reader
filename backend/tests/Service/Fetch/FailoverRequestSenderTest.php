<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\GuardedUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class FailoverRequestSenderTest extends TestCase
{
    private const array DUAL_STACK = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'];

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

        $response = (new FailoverRequestSender($client))
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('served over IPv4', $response->getContent());
    }

    public function testReturnsTheFirstResponseWhenItArrives(): void
    {
        $client = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);

        $response = (new FailoverRequestSender($client))
            ->send('GET', 'https://example.com/post', new GuardedUrl('example.com', ['93.184.216.34']), []);

        self::assertSame('ok', $response->getContent());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testDoesNotFailOverOnAnHttpErrorStatus(): void
    {
        // A 404 is a real answer, not a dead route: the sender must return it and
        // never burn a second family retrying a host that is plainly reachable.
        $client = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 404]),
            new MockResponse('unexpected second try', ['http_code' => 200]),
        ]);

        $response = (new FailoverRequestSender($client))
            ->send('GET', 'https://dual.example.com/missing', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testThrowsTheTransportErrorWhenEveryFamilyFails(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['error' => 'Network is unreachable']),
        );

        $this->expectException(TransportExceptionInterface::class);

        (new FailoverRequestSender($client))
            ->send('GET', 'https://dual.example.com/post', new GuardedUrl('dual.example.com', self::DUAL_STACK), []);
    }
}
