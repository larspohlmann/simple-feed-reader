<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\GoogleOAuthProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleOAuthProviderTest extends TestCase
{
    public function testTheAuthorizationUrlCarriesEveryRequiredParameter(): void
    {
        $url = $this->provider()->getAuthorizationUrl('the-state', 'the-nonce', 'the-challenge');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        $query = [];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame('client-id', $query['client_id'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('openid email', $query['scope'] ?? null);
        self::assertSame('the-state', $query['state'] ?? null);
        self::assertSame('the-nonce', $query['nonce'] ?? null);
        self::assertSame('the-challenge', $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('https://app.test/api/auth/oauth/google/callback', $query['redirect_uri'] ?? null);
    }

    public function testItDoesNotRequestTheProfileScope(): void
    {
        // We display an email address and nothing else. Asking for `profile`
        // would put a longer consent screen in front of every user in exchange
        // for data the application has no field to put.
        $url = $this->provider()->getAuthorizationUrl('s', 'n', 'c');

        self::assertStringNotContainsString('profile', $url);
    }

    public function testItIsNotConfiguredWithoutCredentials(): void
    {
        $provider = new GoogleOAuthProvider(
            new MockHttpClient(),
            new MockClock(),
            'https://app.test',
            '',
            '',
        );

        self::assertFalse($provider->isConfigured());
    }

    public function testItIsConfiguredWithCredentials(): void
    {
        self::assertTrue($this->provider()->isConfigured());
        self::assertSame('google', $this->provider()->getName());
    }

    /**
     * The endpoint and the accepted issuers are `protected`, so the only honest
     * way to pin them is to run an exchange and look at what went over the
     * wire. Both are part of this class's security contract — the token
     * endpoint is the host whose TLS certificate stands in for the ID token's
     * signature — so leaving them untested would leave the interesting half of
     * this class untested.
     */
    public function testAnExchangeHitsGooglesTokenEndpointAndAcceptsBothIssuerSpellings(): void
    {
        foreach (['https://accounts.google.com', 'accounts.google.com'] as $issuer) {
            $seenUrl = null;
            $client = new MockHttpClient(function (string $method, string $url) use (&$seenUrl, $issuer) {
                $seenUrl = $url;

                return self::tokenResponse($issuer);
            });

            $identity = $this->provider($client)->exchangeCode('the-code', 'the-verifier', 'the-nonce');

            self::assertSame('https://oauth2.googleapis.com/token', $seenUrl);
            self::assertSame('google', $identity->provider);
            self::assertSame('sub-123', $identity->providerUserId);
            self::assertTrue($identity->emailVerified);
        }
    }

    private function provider(?MockHttpClient $client = null): GoogleOAuthProvider
    {
        return new GoogleOAuthProvider(
            $client ?? new MockHttpClient(),
            new MockClock('2026-07-21 12:00:00', 'UTC'),
            'https://app.test',
            'client-id',
            'client-secret',
        );
    }

    private static function tokenResponse(string $issuer): MockResponse
    {
        $encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $claims = json_encode([
            'sub' => 'sub-123',
            'aud' => 'client-id',
            'iss' => $issuer,
            'exp' => (new \DateTimeImmutable('2026-07-21 12:05:00', new \DateTimeZone('UTC')))->getTimestamp(),
            'nonce' => 'the-nonce',
            'email' => 'bob@example.com',
            'email_verified' => true,
        ], \JSON_THROW_ON_ERROR);

        $idToken = $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode($claims) . '.signature';

        return new MockResponse(
            json_encode(['id_token' => $idToken], \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
