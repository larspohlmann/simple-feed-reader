<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The CORS policy asserted on the wire, because it is a browser contract and
 * every part of it fails silently when read off the source instead.
 *
 * What makes this worth a file of its own: the OAuth exchange is a CREDENTIALED
 * cross-origin request — it needs the `__Host-oauth_flow` cookie to prove the
 * browser redeeming a login code is the one that earned it — and a browser
 * attaches cookies to such a request only when the response says
 * `Access-Control-Allow-Credentials: true` AND names a specific origin. It
 * rejects `*` outright in that combination. So a policy that looked permissive
 * enough while returning `*` would break every OAuth sign-in with a 400 that is
 * indistinguishable from a bad code.
 *
 * The frontend origin below is `http://localhost:4200`, which is what
 * APP_FRONTEND_URL resolves to in the test environment. Different PORT from the
 * backend, which makes it a different ORIGIN (so CORS applies) but the same
 * SITE (so cookies are unaffected by SameSite). Both axes matter and they are
 * not the same axis.
 */
final class CorsListenerTest extends WebTestCase
{
    private const ORIGIN = 'https://localhost';
    private const FRONTEND = 'http://localhost:4200';
    private const EXCHANGE = '/api/auth/oauth/exchange';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    /**
     * The preflight the browser sends before the exchange can happen at all.
     *
     * It must be answered without routing or the firewall intervening: a
     * preflight carries no cookie and no `Authorization` header by
     * specification, so a 401 or a 405 here would stop the real request from
     * ever being sent.
     */
    public function testThePreflightForTheExchangeIsAnsweredWithCredentialedHeaders(): void
    {
        $this->preflight(self::EXCHANGE, 'POST');

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::FRONTEND);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
        self::assertStringContainsString('POST', $this->header('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', $this->header('Access-Control-Allow-Headers'));
    }

    /**
     * The single most important assertion in this file.
     *
     * `*` is not merely loose here — it is inoperative. A browser refuses a
     * credentialed request answered with a wildcard origin, so this would break
     * OAuth outright rather than only weakening it. It would also be wrong
     * regardless of that.
     */
    public function testTheAllowedOriginIsNeverAWildcard(): void
    {
        $this->preflight(self::EXCHANGE, 'POST');
        self::assertNotSame('*', $this->header('Access-Control-Allow-Origin'));

        $this->exchangeFrom(self::FRONTEND);
        self::assertNotSame('*', $this->header('Access-Control-Allow-Origin'));
    }

    /**
     * The headers must be on the ACTUAL response too, not only the preflight.
     * A browser re-checks them on the real response and discards the body if
     * they are missing — with the preflight cached, that failure would appear
     * long after the change that caused it.
     *
     * A 400 is the expected status: the code is nonsense and there is no flow
     * cookie. The point is the headers, which must be present on an error
     * response exactly as on a success — otherwise the SPA cannot read WHY it
     * failed, and every failure becomes an opaque network error.
     */
    public function testTheActualResponseCarriesTheHeadersEvenWhenItIsAnError(): void
    {
        $this->exchangeFrom(self::FRONTEND);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::FRONTEND);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Any other origin gets nothing — not a reflected origin, not a wildcard,
     * not a partial match. `http://localhost:4201` is the interesting case: it
     * differs from the allowed origin only in the port, which a comparison
     * written against the host would wave through.
     */
    public function testAnOriginThatIsNotTheConfiguredOneGetsNoHeaders(): void
    {
        foreach (['https://evil.test', 'http://localhost:4201', 'http://localhost:4200.evil.test'] as $origin) {
            $this->exchangeFrom($origin);

            self::assertNull(
                $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'),
                "{$origin} must not be allowed",
            );
            self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Credentials'));
        }
    }

    /**
     * A preflight from a disallowed origin is NOT short-circuited into a 204.
     * It falls through to the router, so this listener can never turn an
     * unknown URL into a success — and the browser blocks the real request
     * anyway, on the absent headers.
     */
    public function testAPreflightFromADisallowedOriginIsNotAnsweredWithSuccess(): void
    {
        $this->preflight(self::EXCHANGE, 'POST', 'https://evil.test');

        self::assertNotSame(204, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * `Vary: Origin` whether or not anything else is added, because the answer
     * depends on the origin. A shared cache that missed this could serve an
     * allowed origin a copy stored for a disallowed one, breaking every
     * credentialed call for as long as the entry lived.
     */
    public function testTheResponseVariesByOriginEvenForARequestWithNoOrigin(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Origin', $this->header('Vary'));
    }

    /**
     * The same-origin deployment, which is the likely production shape and the
     * one a cross-origin-only config would break.
     *
     * A browser applies no CORS at all here, so what is being asserted is that
     * the listener stays out of the way: an ordinary request with no `Origin`
     * header is answered normally, headers or no headers.
     */
    public function testARequestWithNoOriginIsUnaffected(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    // -- helpers ----------------------------------------------------------

    private function preflight(string $uri, string $method, string $origin = self::FRONTEND): void
    {
        $this->client->request('OPTIONS', self::ORIGIN . $uri, server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
    }

    private function exchangeFrom(string $origin): void
    {
        $this->client->request(
            'POST',
            self::ORIGIN . self::EXCHANGE,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origin],
            content: (string) json_encode(['code' => str_repeat('a', 64)]),
        );
    }

    private function header(string $name): string
    {
        return (string) $this->client->getResponse()->headers->get($name);
    }
}
