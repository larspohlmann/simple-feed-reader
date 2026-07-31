<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\FlowCookie;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class FlowCookieTest extends TestCase
{
    public function testTheIssuedCookieCarriesEveryLoadBearingAttribute(): void
    {
        $issued = $this->issue();

        $this->assertSame(FlowCookie::NAME, $issued->getName());
        $this->assertSame('/', $issued->getPath());
        $this->assertNull($issued->getDomain());
        $this->assertTrue($issued->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_NONE, $issued->getSameSite());
        // The __Host- prefix mandates Secure; over HTTPS the deployment resolves
        // the create() default to true. Model that here.
        $issued->setSecureDefault(true);
        $this->assertTrue($issued->isSecure());
    }

    public function testTheClearMatchesTheSetOnEveryAttribute(): void
    {
        // The whole point of one collaborator owning both: a browser only clears
        // a cookie whose attributes match the ones it was set with. If they drift,
        // the clear silently does nothing — so assert they agree.
        $issued = $this->issue();
        $issued->setSecureDefault(true);

        $response = new Response();
        (new FlowCookie(new MockClock('2026-07-31 12:00:00')))->clearFrom($response);
        $cleared = $this->flowCookieOn($response);

        $this->assertSame($issued->getName(), $cleared->getName());
        $this->assertSame($issued->getPath(), $cleared->getPath());
        $this->assertSame($issued->getDomain(), $cleared->getDomain());
        $this->assertSame($issued->isSecure(), $cleared->isSecure());
        $this->assertSame($issued->isHttpOnly(), $cleared->isHttpOnly());
        $this->assertSame($issued->getSameSite(), $cleared->getSameSite());
    }

    public function testTheClearExpiresTheCookieInThePast(): void
    {
        $response = new Response();
        (new FlowCookie(new MockClock('2026-07-31 12:00:00')))->clearFrom($response);

        $now = (new \DateTimeImmutable())->getTimestamp();
        $this->assertLessThan($now, $this->flowCookieOn($response)->getExpiresTime());
    }

    private function issue(): Cookie
    {
        return (new FlowCookie(new MockClock('2026-07-31 12:00:00')))->issue('browser-token');
    }

    private function flowCookieOn(Response $response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (FlowCookie::NAME === $cookie->getName()) {
                return $cookie;
            }
        }

        $this->fail('The response carries no ' . FlowCookie::NAME . ' cookie.');
    }
}
