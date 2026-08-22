<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\ProxyConfig;
use PHPUnit\Framework\TestCase;

final class FetchAttemptTest extends TestCase
{
    private function attempt(): FetchAttempt
    {
        return FetchAttempt::start(
            7,
            new FetchTicket('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'),
        );
    }

    public function testStartsAtTheTicketUrlWithNoRedirects(): void
    {
        $attempt = $this->attempt();

        self::assertSame(7, $attempt->key);
        self::assertSame('https://example.com/feed', $attempt->url);
        self::assertFalse($attempt->permanentRedirect);
        self::assertTrue($attempt->canFollowRedirect());
    }

    public function testATemporaryRedirectMovesTheUrlWithoutMarkingItPermanent(): void
    {
        $next = $this->attempt()->followedTo('https://example.com/moved', permanent: false);

        self::assertSame('https://example.com/moved', $next->url);
        self::assertFalse($next->permanentRedirect);
        // The conditional-GET headers follow the chain.
        self::assertSame('"v1"', $next->ticket->etag);
    }

    public function testAPermanentRedirectAnywhereInTheChainIsRemembered(): void
    {
        $next = $this->attempt()
            ->followedTo('https://example.com/one', permanent: true)
            ->followedTo('https://example.com/two', permanent: false);

        self::assertTrue($next->permanentRedirect);
    }

    public function testTheRedirectBudgetIsExhaustedAfterFiveHops(): void
    {
        $attempt = $this->attempt();
        for ($hop = 0; $hop < 5; $hop++) {
            self::assertTrue($attempt->canFollowRedirect(), sprintf('hop %d should be allowed', $hop));
            $attempt = $attempt->followedTo('https://example.com/' . $hop, permanent: false);
        }

        self::assertFalse($attempt->canFollowRedirect());
    }

    public function testProxiedTicketMakesTheAttemptProxied(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);
        $attempt = FetchAttempt::start(1, new FetchTicket('https://feed.example', proxy: $proxy));

        self::assertTrue($attempt->isProxied());
        self::assertSame($proxy, $attempt->effectiveProxy());
    }

    public function testWithoutProxyStripsTheEgressOnce(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);
        $attempt = FetchAttempt::start(1, new FetchTicket('https://feed.example', proxy: $proxy));

        $direct = $attempt->withoutProxy();

        self::assertFalse($direct->isProxied());
        self::assertNull($direct->effectiveProxy());
        self::assertSame('https://feed.example', $direct->url);
        // A redirect lands on a fresh host, so the pin restarts at the first address.
        self::assertSame(0, $direct->pinnedAddressAttempt);
    }

    public function testDirectTicketIsNotProxied(): void
    {
        $attempt = FetchAttempt::start(1, new FetchTicket('https://feed.example'));

        self::assertFalse($attempt->isProxied());
    }

    public function testWithProxyEnrichesAPlainTicket(): void
    {
        $proxy = new ProxyConfig(ProxyType::Http, 'p', 8080, null, null);
        $ticket = (new FetchTicket('https://feed.example', 'etag', 'lm'))->withProxy($proxy);

        self::assertSame($proxy, $ticket->proxy);
        self::assertSame('etag', $ticket->etag);
        self::assertSame('lm', $ticket->lastModified);
    }
}
