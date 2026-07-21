<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\AbstractOidcProvider;
use App\Service\OAuth\AppleClientSecretFactory;
use App\Service\OAuth\AppleOAuthProvider;
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

    /**
     * The likely half-configured deployment: somebody pasted the key and never
     * filled in the team id. Apple must be invisible, not half-working — the
     * check has to happen here, before anyone is redirected to a consent screen
     * whose callback cannot possibly be completed.
     */
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
     * A structural guard, not a behavioural one.
     *
     * Apple's `form_post` callback carries an `id_token` in the request body.
     * That token did NOT arrive by direct communication with the token
     * endpoint, so the OIDC Core §3.1.3.7 carve-out that lets this codebase
     * skip signature verification does not cover it — trusting it would need
     * full JWKS verification that nothing here does.
     *
     * The defence is that there is no method to hand such a token to:
     * readIdentity() is private and exchangeCode() is final, so no subclass and
     * no future edit can route a callback-supplied token into the claim reader
     * without deleting one of these two modifiers. This test fails the moment
     * somebody does.
     */
    public function testTheClaimReaderCannotBeHandedACallbackSuppliedToken(): void
    {
        $parent = new \ReflectionClass(AbstractOidcProvider::class);

        self::assertTrue(
            $parent->getMethod('readIdentity')->isPrivate(),
            'readIdentity() must stay private: a protected one could be fed the callback body id_token.',
        );
        self::assertTrue(
            $parent->getMethod('exchangeCode')->isFinal(),
            'exchangeCode() must stay final: an override could skip the token-endpoint fetch entirely.',
        );

        // And the Apple subclass must not have grown its own door.
        $apple = new \ReflectionClass(AppleOAuthProvider::class);
        foreach ($apple->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== AppleOAuthProvider::class) {
                continue;
            }

            self::assertNotSame(
                'readIdentity',
                $method->getName(),
                'AppleOAuthProvider must not reimplement the claim reader.',
            );
        }
    }

    private function provider(): AppleOAuthProvider
    {
        return $this->providerWith($this->factory(
            self::SERVICES_ID,
            'TESTTEAMID',
            'TESTKEYID1',
            (string) file_get_contents(__DIR__ . '/../../Fixtures/oauth/apple-test-key.p8'),
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
