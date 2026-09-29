<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The CORS policy on the wire. The test frontend (`http://localhost:4200`) is another origin than the backend but the
 * same site, so CORS applies and SameSite does not.
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

    /** A preflight carries no credentials, so routing or the firewall answering it would block the exchange. */
    public function testThePreflightForTheExchangeIsAnsweredWithCredentialedHeaders(): void
    {
        $this->preflight(self::EXCHANGE, 'POST');

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::FRONTEND);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
        self::assertStringContainsString('POST', $this->header('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', $this->header('Access-Control-Allow-Headers'));
    }

    /** A credentialed request answered with `*` is refused by the browser: it would break OAuth outright. */
    public function testTheAllowedOriginIsNeverAWildcard(): void
    {
        $this->preflight(self::EXCHANGE, 'POST');
        self::assertNotSame('*', $this->header('Access-Control-Allow-Origin'));

        $this->exchangeFrom(self::FRONTEND);
        self::assertNotSame('*', $this->header('Access-Control-Allow-Origin'));
    }

    /**
     * The real response needs the headers too, errors included (this 400 is a nonsense code): without them the SPA
     * cannot read why a call failed.
     */
    public function testTheActualResponseCarriesTheHeadersEvenWhenItIsAnError(): void
    {
        $this->exchangeFrom(self::FRONTEND);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::FRONTEND);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
    }

    /** Any other origin gets nothing; `:4201` differs only in the port, which a host-only comparison would pass. */
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

    /** A disallowed preflight falls through to the router, never a 204. */
    public function testAPreflightFromADisallowedOriginIsNotAnsweredWithSuccess(): void
    {
        $this->preflight(self::EXCHANGE, 'POST', 'https://evil.test');

        self::assertNotSame(204, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /** `Vary: Origin` always, or a shared cache could serve one origin's answer to another. */
    public function testTheResponseVariesByOriginEvenForARequestWithNoOrigin(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Origin', $this->header('Vary'));
    }

    /** Same-origin requests send no `Origin`, and the listener must stay out of their way. */
    public function testARequestWithNoOriginIsUnaffected(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

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
