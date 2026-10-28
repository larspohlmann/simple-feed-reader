<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class RedirectFollowerTest extends TestCase
{
    use NoEgressProxy;

    /** @param callable|iterable<MockResponse> $responses */
    private function follower(callable|iterable $responses): RedirectFollower
    {
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        return FetchWiring::redirectFollower(
            new MockHttpClient($responses),
            $this->noEgressProxy(),
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

        $landed = $follower->follow('https://example.com/start', [], new RedirectBudget(5));

        self::assertSame('https://cdn.example.com/master.m3u8', $landed->url);
        self::assertSame(200, $landed->status);
        self::assertTrue($landed->isSuccess());
        self::assertSame('#EXTM3U', $landed->response->getContent(false));
    }

    public function testReturnsANonSuccessLandingInsteadOfThrowing(): void
    {
        $landed = $this->follower([new MockResponse('gone', ['http_code' => 404])])
            ->follow('https://example.com/missing', [], new RedirectBudget(5));

        self::assertSame(404, $landed->status);
        self::assertFalse($landed->isSuccess());
    }

    public function testStatus300IsNotASuccessfulLanding(): void
    {
        $landed = $this->follower([new MockResponse('choices', ['http_code' => 300])])
            ->follow('https://example.com/ambiguous', [], new RedirectBudget(5));

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
            $follower->follow('https://example.com/start', [], new RedirectBudget(5));
            self::fail('a hop into link-local space must be refused');
        } catch (RedirectChainException $exception) {
            self::assertStringContainsString('169.254.169.254', $exception->getMessage());
        }
        self::assertSame(['https://example.com/start'], $requested, 'the blocked host is never requested');
    }

    public function testRefusesMoreHopsThanAllowed(): void
    {
        $follower = $this->follower(static fn (): MockResponse => self::redirect('/again'));

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('more than 2 redirects');
        $follower->follow('https://example.com/start', [], new RedirectBudget(2));
    }

    public function testLandsWhenTheChainUsesExactlyTheAllowedHops(): void
    {
        $follower = $this->follower(
            [self::redirect('/a'), self::redirect('/b'), new MockResponse('ok', ['http_code' => 200])],
        );

        $landed = $follower->follow('https://example.com/start', [], new RedirectBudget(2));

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
        $follower->follow('https://example.com/start', [], new RedirectBudget(2));
    }

    public function testRefusesARedirectWithoutLocation(): void
    {
        $follower = $this->follower([new MockResponse('', ['http_code' => 302])]);

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('redirect without Location');
        $follower->follow('https://example.com/start', [], new RedirectBudget(5));
    }

    public function testWrapsATransportFailure(): void
    {
        $follower = $this->follower([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(RedirectChainException::class);
        $follower->follow('https://example.com/start', [], new RedirectBudget(5));
    }

    public function testTheClientNeverFollowsOnItsOwnWhateverTheCallerPasses(): void
    {
        $seen = null;
        $follower = $this->follower(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['max_redirects'];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $follower->follow(
            'https://example.com/start',
            ['max_redirects' => 5, 'timeout' => 1.0],
            new RedirectBudget(5),
        );

        self::assertSame(0, $seen);
    }

    public function testSpendsOneRedirectPerHopFromTheBudget(): void
    {
        $budget = new RedirectBudget(5);
        $follower = $this->follower([
            self::redirect('/a'),
            self::redirect('/b'),
            new MockResponse('ok', ['http_code' => 200]),
        ]);

        $follower->follow('https://example.com/start', [], $budget);

        self::assertSame(2, $budget->redirectsTaken());
    }

    public function testASecondChainOnTheSameBudgetFollowsOnlyTheRedirectsTheFirstLeft(): void
    {
        $budget = new RedirectBudget(2);
        $follower = $this->follower([
            self::redirect('/a'),
            new MockResponse('ok', ['http_code' => 200]),
            self::redirect('/b'),
            self::redirect('/c'),
            new MockResponse('ok', ['http_code' => 200]),
        ]);
        $follower->follow('https://example.com/first', [], $budget);

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('https://example.com/second: more than 2 redirects');
        $follower->follow('https://example.com/second', [], $budget);
    }

    public function testFollowHeadSendsEveryHopAsHead(): void
    {
        $methods = [];
        $follower = $this->follower(static function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return \count($methods) === 1
                ? self::redirect('https://cdn.example.com/a.jpg')
                : new MockResponse('', ['http_code' => Response::HTTP_OK]);
        });

        $landed = $follower->followHead('https://example.com/a.jpg', [], new RedirectBudget(5));

        self::assertSame(['HEAD', 'HEAD'], $methods);
        self::assertSame('https://cdn.example.com/a.jpg', $landed->url);
    }

    public function testFollowStillSendsGet(): void
    {
        $methods = [];
        $follower = $this->follower(static function (string $method) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('', ['http_code' => Response::HTTP_OK]);
        });

        $follower->follow('https://example.com/a.jpg', [], new RedirectBudget(5));

        self::assertSame(['GET'], $methods);
    }
}
