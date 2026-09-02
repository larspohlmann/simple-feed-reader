<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RedirectFollowerTest extends TestCase
{
    /** @param callable|iterable<MockResponse> $responses */
    private function follower(callable|iterable $responses): RedirectFollower
    {
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);

        return new RedirectFollower(
            new FailoverRequestSender(new MockHttpClient($responses), $proxy),
            new UrlGuard($dns, new IpValidator()),
        );
    }

    private static function redirect(string $location, int $status = 301): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    public function testLandsOnTheFirstNonRedirectResolvingARelativeLocation(): void
    {
        $follower = $this->follower([
            self::redirect('/moved/here'),
            self::redirect('https://cdn.example.com/master.m3u8', 302),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ]);

        $landed = $follower->follow('https://example.com/start', [], 5);

        self::assertSame('https://cdn.example.com/master.m3u8', $landed->url);
        self::assertSame(200, $landed->status);
        self::assertTrue($landed->isSuccess());
        self::assertSame('#EXTM3U', $landed->response->getContent(false));
    }

    public function testReturnsANonSuccessLandingInsteadOfThrowing(): void
    {
        $landed = $this->follower([new MockResponse('gone', ['http_code' => 404])])
            ->follow('https://example.com/missing', [], 5);

        self::assertSame(404, $landed->status);
        self::assertFalse($landed->isSuccess());
    }

    public function testStatus300IsNotASuccessfulLanding(): void
    {
        $landed = $this->follower([new MockResponse('choices', ['http_code' => 300])])
            ->follow('https://example.com/ambiguous', [], 5);

        self::assertSame(300, $landed->status);
        self::assertFalse($landed->isSuccess());
    }

    public function testGuardsEveryHopSoARedirectIntoLinkLocalSpaceIsRefused(): void
    {
        $requested = [];
        $follower = $this->follower(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return self::redirect('http://169.254.169.254/latest/meta-data/');
        });

        try {
            $follower->follow('https://example.com/start', [], 5);
            self::fail('a hop into link-local space must be refused');
        } catch (RedirectChainException $e) {
            self::assertStringContainsString('169.254.169.254', $e->getMessage());
        }
        self::assertSame(['https://example.com/start'], $requested, 'the blocked host is never requested');
    }

    public function testRefusesMoreHopsThanAllowed(): void
    {
        $follower = $this->follower(static fn (): MockResponse => self::redirect('/again'));

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('more than 2 redirects');
        $follower->follow('https://example.com/start', [], 2);
    }

    public function testLandsWhenTheChainUsesExactlyTheAllowedHops(): void
    {
        $follower = $this->follower([self::redirect('/a'), self::redirect('/b'), new MockResponse('ok', ['http_code' => 200])]);

        $landed = $follower->follow('https://example.com/start', [], 2);

        self::assertTrue($landed->isSuccess());
    }

    public function testThrowsOnlyAfterTheChainExceedsTheAllowedHops(): void
    {
        $follower = $this->follower([
            self::redirect('/a'),
            self::redirect('/b'),
            self::redirect('/c'),
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('more than 2 redirects');
        $follower->follow('https://example.com/start', [], 2);
    }

    public function testRefusesARedirectWithoutLocation(): void
    {
        $follower = $this->follower([new MockResponse('', ['http_code' => 302])]);

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('redirect without Location');
        $follower->follow('https://example.com/start', [], 5);
    }

    public function testWrapsATransportFailure(): void
    {
        $follower = $this->follower([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(RedirectChainException::class);
        $follower->follow('https://example.com/start', [], 5);
    }

    public function testTheClientNeverFollowsOnItsOwnWhateverTheCallerPasses(): void
    {
        $seen = null;
        $follower = $this->follower(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['max_redirects'];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $follower->follow('https://example.com/start', ['max_redirects' => 5, 'timeout' => 1.0], 5);

        self::assertSame(0, $seen);
    }
}
