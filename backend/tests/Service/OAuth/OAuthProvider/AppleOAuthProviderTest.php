<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\OAuthProvider;

use App\Service\OAuth\Factory\AppleClientSecretFactory;
use App\Service\OAuth\OAuthProvider\AbstractOidcProvider;
use App\Service\OAuth\OAuthProvider\AppleOAuthProvider;
use App\Tests\Support\AppleTestKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class AppleOAuthProviderTest extends TestCase
{
    private const SERVICES_ID = 'test.apple.services.id';

    public function testTheAuthorizationUrlRequestsFormPostAndTheEmailScope(): void
    {
        $url = $this->provider()->getAuthorizationUrl('the-state', 'the-nonce', 'the-challenge');

        self::assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $url);

        $query = [];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame(self::SERVICES_ID, $query['client_id'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('email', $query['scope'] ?? null);
        // Required by Apple whenever a scope is requested: the callback becomes
        // a cross-site POST. Omitting it makes Apple reject the request.
        self::assertSame('form_post', $query['response_mode'] ?? null);
        self::assertSame('the-state', $query['state'] ?? null);
        self::assertSame('the-nonce', $query['nonce'] ?? null);
        self::assertSame('the-challenge', $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        // Built from APP_BACKEND_URL, never from the incoming request's Host
        // header — see AbstractOidcProvider::getRedirectUri().
        self::assertSame('https://app.test/api/auth/oauth/apple/callback', $query['redirect_uri'] ?? null);
    }

    public function testItIsUnconfiguredWhenTheSecretFactoryIs(): void
    {
        $provider = $this->providerWith($this->factory('', '', '', ''));

        self::assertFalse($provider->isConfigured());
        self::assertSame('apple', $provider->getName());
    }

    /** Key pasted, team id missing: Apple must be invisible rather than fail on the way back. */
    public function testAPartiallyConfiguredDeploymentIsUnconfigured(): void
    {
        $provider = $this->providerWith(
            $this->factory(self::SERVICES_ID, '', 'TESTKEYID1', 'a-key'),
        );

        self::assertFalse($provider->isConfigured());
    }

    public function testItIsConfiguredWhenEveryAppleValueIsPresent(): void
    {
        self::assertTrue($this->provider()->isConfigured());
    }

    /**
     * Apple's callback carries an `id_token` the signature exemption does not cover; this provider must not grow its
     * own exchange or claim reading to trust it. OidcBoundaryTest pins the general rule.
     */
    public function testAppleCannotRouteItsCallbackTokenIntoTheVerifier(): void
    {
        self::assertTrue(
            (new \ReflectionClass(AbstractOidcProvider::class))->getMethod('exchangeCode')->isFinal(),
            'exchangeCode() must stay final: an override could skip the token-endpoint fetch entirely.',
        );

        $apple = new \ReflectionClass(AppleOAuthProvider::class);

        foreach ($apple->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== AppleOAuthProvider::class) {
                continue;
            }

            // The subclass supplies configuration and nothing else. A method
            // here that reads claims or verifies a token would be reimplementing
            // the boundary rather than standing behind it.
            self::assertDoesNotMatchRegularExpression(
                '/^(readIdentity|verify|decodeClaims|exchangeCode)$/',
                $method->getName(),
                'AppleOAuthProvider must not reimplement any part of the claim reader.',
            );
        }
    }

    private function provider(): AppleOAuthProvider
    {
        return $this->providerWith($this->factory(
            self::SERVICES_ID,
            'TESTTEAMID',
            'TESTKEYID1',
            AppleTestKey::privateKey(),
        ));
    }

    private function providerWith(AppleClientSecretFactory $factory): AppleOAuthProvider
    {
        return new AppleOAuthProvider(
            new MockHttpClient(),
            new MockClock('2026-07-21 12:00:00'),
            'https://app.test',
            $factory,
            self::SERVICES_ID,
        );
    }

    private function factory(
        string $servicesId,
        string $teamId,
        string $keyId,
        string $privateKey,
    ): AppleClientSecretFactory {
        return new AppleClientSecretFactory(
            new MockClock('2026-07-21 12:00:00'),
            $servicesId,
            $teamId,
            $keyId,
            $privateKey,
        );
    }
}
