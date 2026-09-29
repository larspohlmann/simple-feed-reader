<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\OAuthController;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\EventListener\AddUserIdClaimOnTokenIssueListener;
use App\Repository\UserRepository;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Tests\Support\FakeOAuthProvider;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The OAuth flow over HTTP. The client never reboots, or the fake registry would last one request; the registry, not
 * a provider, is replaced; and every request is `https`, because the jar withholds the `Secure` flow cookie otherwise.
 */
final class OAuthFlowTest extends WebTestCase
{
    /**
     * Requests are absolute rather than relative so the scheme is explicit at
     * every call site — see the class docblock for why `https` is load-bearing.
     */
    private const ORIGIN = 'https://localhost';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        // The container must survive between requests, or the fake registry
        // installed by fakeProvider() lasts exactly one request. See the class
        // docblock.
        $this->client->disableReboot();

        // The oauth_start limiter's filesystem pool outlives the kernel and the run: without this clear, the second
        // `composer test` answers 429.
        $this->rateLimiterCache()->clear();
    }

    /**
     * Redirect to JWT, with the property the whole design exists for asserted
     * in the middle: the callback hands over a code, never a token.
     */
    public function testTheHappyPathTakesAnActiveUserFromRedirectToJwt(): void
    {
        $bob = $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        // 1. Start: we redirect to the provider, carrying a state we minted.
        $this->startFlow();
        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('https://provider.test/authorize', $this->location());

        $state = $provider->lastState;
        self::assertIsString($state);

        // The binding rode out with the redirect: only this happy path notices if the cookie is never stored.
        self::assertNotSame('', $this->flowCookieValue());

        // 2. Callback: we redirect to the SPA with a code, never a token.
        $this->requestCallback(['state' => $state, 'code' => 'provider-code']);
        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('http://localhost:4200/auth/callback?code=', $this->location());
        $this->assertNoTokenInLocation();

        // The controller forwarded THIS flow's PKCE verifier and nonce, which
        // is what a state mix-up would get wrong.
        self::assertCount(1, $provider->exchanges);
        self::assertSame('provider-code', $provider->exchanges[0]['code']);
        self::assertSame($provider->lastNonce, $provider->exchanges[0]['nonce']);
        self::assertNotSame('', $provider->exchanges[0]['codeVerifier']);

        $code = $this->codeFromLocation();

        // 3. Exchange: the SPA POSTs the code and gets the JWT.
        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $token = $this->payload()['token'] ?? null;
        self::assertIsString($token);
        self::assertCount(3, explode('.', $token), 'a JWT has three dot-separated parts');

        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertSame($bob->getId(), $tokens->parse($token)[AddUserIdClaimOnTokenIssueListener::CLAIM] ?? null);

        // 4. The token actually works, on the same route the password login's
        // token is proved against.
        $this->client->request(
            'GET',
            self::ORIGIN . '/api/me',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseIsSuccessful();
        self::assertSame('bob@example.com', $this->payload()['email']);
    }

    /**
     * A first sign-in with no matching account creates one, in the queue, with
     * no password — the linker's rules, observed through the endpoint rather
     * than by calling it.
     */
    public function testAFirstSignInCreatesAPendingAccountAndAnIdentity(): void
    {
        $this->completeCallback(new OAuthIdentityModel('google', 'sub-new', 'new@example.com', true));

        $user = $this->userByEmail('new@example.com');

        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getPasswordHash());
        self::assertCount(1, $this->entityManager()->getRepository(UserIdentity::class)->findAll());
    }

    /**
     * Through the endpoint on purpose: the `.invalid` refusal belongs on the registration DTO, and on User it would
     * break every addressless Apple signup while every linker unit test stayed green.
     */
    public function testAnAddresslessIdentityStillGetsAnAccountWithAPlaceholderAddress(): void
    {
        $this->completeCallback(new OAuthIdentityModel('apple', 'sub-addressless', null, false));

        $identities = $this->entityManager()->getRepository(UserIdentity::class)->findAll();
        self::assertCount(1, $identities);

        $user = $identities[0]->getUser();
        self::assertStringEndsWith('@oauth.invalid', $user->getEmail());
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getPasswordHash());
    }

    /**
     * The status gate sits at the exchange so the answer can say why: the password login's problem+json, with the same
     * `type` and `accountStatus`.
     */
    public function testAPendingApprovalUserGetsAProperExplanationNotAGenericFailure(): void
    {
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-new', 'new@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('account_not_active', $this->payload()['type']);
        self::assertSame('pending_approval', $this->payload()['accountStatus']);
    }

    /** The linker returns a suspended user unchanged; the exchange's status gate is all that stops the JWT. */
    public function testASuspendedUserCannotExchangeACode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Suspended);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('suspended', $this->payload()['accountStatus']);
        self::assertArrayNotHasKey('token', $this->payload());
    }

    /**
     * Rejected is the other status the linker returns untouched, and the one
     * with the strongest claim to a second look: an admin said no, and OAuth
     * cannot overturn that by proving an address the admin already saw.
     */
    public function testARejectedUserCannotExchangeACode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Rejected);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('rejected', $this->payload()['accountStatus']);
    }

    public function testALoginCodeCannotBeUsedTwice(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnknownLoginCodeIsRejected(): void
    {
        $this->postJson('/api/auth/oauth/exchange', ['code' => str_repeat('a', 64)]);

        self::assertResponseStatusCodeSame(400);
    }

    /** The state and login-code stores key on distinct prefixes; a state must buy nothing at the exchange. */
    public function testAStateValueIsNotAcceptedAsALoginCode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->postJson('/api/auth/oauth/exchange', ['code' => $state]);

        self::assertResponseStatusCodeSame(400);
    }

    /** A deleted account's live code gets the bad-code answer, not a 500. */
    public function testACodeForAnAccountDeletedBeforeTheExchangeIsRejected(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        // user_identity's FK carries ON DELETE CASCADE, so removing the user
        // takes the identity row with it, exactly as an account purge would.
        $entityManager = $this->entityManager();
        $entityManager->remove($this->userByEmail('bob@example.com'));
        $entityManager->flush();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayNotHasKey('token', $this->payload());
    }

    /**
     * Login CSRF: an attacker's genuine, unspent state and code, opened in a victim's browser (the cleared jar), must
     * mint no code and spend nothing at the provider.
     */
    public function testACallbackFromABrowserThatDidNotStartTheFlowIsRefused(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        // Everything the attacker cannot carry across to the victim's browser.
        $this->client->getCookieJar()->clear();

        $this->requestCallback(['state' => $state, 'code' => 'provider-code']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=invalid_state', $this->location());

        // No login code was minted, and the provider never saw the attacker's authorization code.
        self::assertStringNotContainsString('code=', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /**
     * The same refusal for a cookie that is present and well formed but wrong —
     * the path that reaches the hash_equals comparison rather than short-
     * circuiting on an absent cookie. A guessing attacker gets exactly this.
     */
    public function testACallbackWithAWrongFlowCookieIsRefused(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->replaceFlowCookie(str_repeat('a', 64));

        $this->requestCallback(['state' => $state, 'code' => 'provider-code']);

        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertStringNotContainsString('code=', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /** A wrong cookie burns the state, so the binding cannot be brute-forced against one live state. */
    public function testAFailedBindingCheckBurnsTheStateSoItCannotBeRetried(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;
        $genuine = $this->flowCookieValue();

        $this->replaceFlowCookie(str_repeat('a', 64));
        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('error=invalid_state', $this->location());

        // Now retry with the RIGHT cookie. The state is gone regardless.
        $this->replaceFlowCookie($genuine);
        $this->requestCallback(['state' => $state, 'code' => 'c']);

        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /**
     * `SameSite=None` because Apple's callback is a cross-site POST, which a `Lax` cookie misses; `__Host-` so no
     * sibling host can write the binding.
     */
    public function testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds(): void
    {
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();

        $cookie = $this->responseCookie(OAuthController::FLOW_COOKIE);

        self::assertTrue($cookie->isSecure(), 'SameSite=None is only honoured on a Secure cookie');
        self::assertTrue($cookie->isHttpOnly(), 'no script needs to read the flow binding');
        self::assertSame('none', $cookie->getSameSite(), "Apple's cross-site POST would not carry a Lax cookie");

        // The two the __Host- prefix mandates, and which browsers enforce by
        // rejecting the cookie outright if they are wrong.
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());

        // The state's ten minutes plus the login code's thirty seconds, so a callback in the state's final second
        // still hands over a code the browser can exchange.
        self::assertEqualsWithDelta(630, $cookie->getExpiresTime() - time(), 5);
    }

    /** The binding is opaque random bytes: set before sign-in on a public endpoint, it must not identify anyone. */
    public function testTheFlowCookieRevealsNothingAndIsClearedWhenTheFlowFails(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();

        $value = $this->flowCookieValue();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
        self::assertStringNotContainsString('bob', $value);

        // Two flows never share a binding, so it cannot correlate visits.
        $this->startFlow();
        self::assertNotSame($value, $this->flowCookieValue());

        // A failed callback clears the binding; the success exit keeps it for the exchange
        // (testTheBindingOutlivesTheCallbackAndDiesAtTheExchange).
        $this->requestCallback(['state' => 'not-a-state', 'code' => 'c']);
        self::assertNull(
            $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost'),
            'a failed flow must not leave its binding in the browser',
        );
    }

    /**
     * The second half of login CSRF: a code from the attacker's own genuine sign-in, opened in a victim's browser (the
     * cleared jar), must not be exchanged.
     */
    public function testALoginCodeCannotBeExchangedByADifferentBrowser(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        // Everything the attacker cannot carry across to the victim's browser.
        $this->client->getCookieJar()->clear();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        $this->assertIndistinguishableFromABadCode();
    }

    /** A well-formed binding from another flow reaches the hash_equals comparison, not just the absent-cookie check. */
    public function testALoginCodeCannotBeExchangedWithSomebodyElsesBinding(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->replaceFlowCookie(str_repeat('b', 64));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        $this->assertIndistinguishableFromABadCode();
    }

    /**
     * A failed binding check must BURN the code, so a mismatch cannot be
     * retried with a different guess against the same live code. The store
     * deletes before it validates; this is the endpoint-level proof.
     */
    public function testAFailedBindingCheckBurnsTheLoginCode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));
        $genuine = $this->flowCookieValue();

        $this->replaceFlowCookie(str_repeat('b', 64));
        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseStatusCodeSame(400);

        // Now retry with the RIGHT binding. The code is gone regardless.
        $this->replaceFlowCookie($genuine);
        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        $this->assertIndistinguishableFromABadCode();
    }

    /**
     * Cleared too early, every sign-in fails like a bad code; never cleared, a public endpoint leaves a durable value
     * in the browser.
     */
    public function testTheBindingOutlivesTheCallbackAndDiesAtTheExchange(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        // Survived the callback, or the happy path below could not work.
        self::assertNotSame('', $this->flowCookieValue());

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseIsSuccessful();

        self::assertNull(
            $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost'),
            'the binding has no purpose once the code it binds has been spent',
        );
    }

    public function testAStateValueCannotBeReplayed(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('code=', $this->location());

        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('error=invalid_state', $this->location());
        $this->assertNoTokenInLocation();
    }

    /**
     * A Google state at Apple's callback must be refused before anything else: `apple` is unconfigured here, so a
     * check after the registry would hide behind its 404.
     */
    public function testAStateIssuedForOneProviderIsRefusedAtAnothersCallback(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;
        $genuine = $this->flowCookieValue();

        $this->client->request(
            'GET',
            self::ORIGIN . '/api/auth/oauth/apple/callback',
            ['state' => $state, 'code' => 'c'],
        );

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertSame([], $provider->exchanges, 'the mismatch must be caught before any exchange');

        // ...and the state was burned on the way, so the mismatch cannot be
        // used to probe a state and then spend it at the right callback.
        // The refusal also cleared the binding, so restore it: only the burn can refuse this retry.
        $this->replaceFlowCookie($genuine);
        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /**
     * A stolen code with no state is refused before the provider hears of it. `invalid_request`, not `invalid_state`:
     * the parameter check runs first, and saying so discloses only what the caller sent.
     */
    public function testACallbackWithNoStateIsRefusedWithoutContactingTheProvider(): void
    {
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->requestCallback(['code' => 'a-stolen-code']);

        self::assertStringContainsString('error=invalid_request', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /**
     * The same refusal, but with a state that is merely wrong rather than
     * absent — the path that DOES reach the store and comes back null.
     */
    public function testACallbackWithAnUnissuedStateIsRefusedWithoutContactingTheProvider(): void
    {
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->requestCallback(['state' => str_repeat('f', 64), 'code' => 'a-stolen-code']);

        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    public function testACallbackWithNoCodeIsRefusedAsAnInvalidRequest(): void
    {
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state]);

        self::assertStringContainsString('error=invalid_request', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    public function testADeclinedConsentScreenRedirectsWithAccessDenied(): void
    {
        $this->requestCallback(['error' => 'access_denied']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=access_denied', $this->location());
    }

    /**
     * The success redirect carries a live login code, so nothing the caller sends may reach the Location host: an open
     * redirect here would hand the attacker's page a credential.
     */
    public function testNothingTheCallerSuppliesCanReachTheLocationHeader(): void
    {
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $hostile = 'https://evil.test/steal';

        foreach ([['error' => $hostile], ['state' => $hostile, 'code' => $hostile]] as $query) {
            $this->requestCallback($query);

            self::assertResponseStatusCodeSame(302);
            self::assertStringStartsWith('http://localhost:4200/auth/callback?error=', $this->location());
            self::assertStringNotContainsString('evil.test', $this->location());
            $this->assertNoTokenInLocation();
        }
    }

    /** Apple posts its callback as a form body; only Google's completes here, and it reads parameters the same way. */
    public function testACallbackPostedAsAFormBodyCompletesTheSameWay(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->client->request('POST', self::ORIGIN . '/api/auth/oauth/google/callback', [
            'state' => $state,
            'code' => 'provider-code',
        ]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('http://localhost:4200/auth/callback?code=', $this->location());
        $this->assertNoTokenInLocation();
    }

    /**
     * A provider failure leaves as a redirect the browser can follow, not as
     * problem+json in the address bar — and it says nothing about what went
     * wrong at the provider.
     */
    public function testAFailedExchangeRedirectsWithAnErrorRatherThanJson(): void
    {
        $provider = $this->failingFakeProvider(new OAuthIdentityModel('google', 'sub-1', null, false));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state, 'code' => 'c']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=exchange_failed', $this->location());
        $this->assertNoTokenInLocation();
        self::assertStringNotContainsString('fake provider was told to fail', $this->location());
    }

    public function testAnUnconfiguredProviderIs404(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/facebook');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('unknown_provider', $this->payload()['type']);
    }

    /**
     * No fake registry here on purpose: this asserts what the REAL container
     * would tell the SPA, which is the only answer worth asserting for a list
     * that decides which buttons get rendered.
     */
    public function testTheProvidersEndpointIsPublicAndListsGoogle(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/providers');

        self::assertResponseIsSuccessful();
        self::assertSame(['providers' => ['google']], $this->payload());
    }

    /**
     * Fired for real: the limiter factory is injected by name, and a renamed `oauth_start` block would silently bind
     * another budget. Twenty pass and the twenty-first does not, which also proves setUp()'s clear.
     */
    public function testTheStartLimiterRefusesTheTwentyFirstAttempt(): void
    {
        $this->fakeProvider(new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', true));

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $this->startFlow();
            self::assertResponseStatusCodeSame(302, "start attempt {$attempt} should be within budget");
        }

        $this->startFlow();

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload()['type']);
        self::assertNotSame('0', $this->client->getResponse()->headers->get('Retry-After'));
    }

    private function fakeProvider(OAuthIdentityModel $identity): FakeOAuthProvider
    {
        return $this->installBeforeTheFirstRequest(FakeOAuthProvider::returning($identity));
    }

    private function failingFakeProvider(OAuthIdentityModel $identity): FakeOAuthProvider
    {
        return $this->installBeforeTheFirstRequest(FakeOAuthProvider::failingExchange($identity));
    }

    /**
     * Replaces the whole registry; see the class docblock for why the provider service is not the seam.
     */
    private function installBeforeTheFirstRequest(FakeOAuthProvider $provider): FakeOAuthProvider
    {
        self::getContainer()->set(
            OAuthProviderRegistry::class,
            new OAuthProviderRegistry([$provider]),
        );

        return $provider;
    }

    /** The binding the browser holds, read from the jar so a cookie the jar rejected (scheme, path) reads as absent. */
    private function flowCookieValue(): string
    {
        $cookie = $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost');
        self::assertNotNull($cookie, 'the flow never set a binding cookie');

        return $cookie->getValue();
    }

    /**
     * Restates the controller's attributes instead of copying the stored cookie, which a failed callback clears; a
     * mismatch would make the jar withhold it and the test pass without reaching the comparison.
     */
    private function replaceFlowCookie(string $value): void
    {
        $this->client->getCookieJar()->set(new BrowserKitCookie(
            OAuthController::FLOW_COOKIE,
            $value,
            null,
            '/',
            'localhost',
            secure: true,
            httponly: true,
            samesite: Cookie::SAMESITE_NONE,
        ));
    }

    /** The cookie as the response set it, attributes intact. */
    private function responseCookie(string $name): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        self::fail("the response set no {$name} cookie");
    }

    /** Runs start + callback and returns the one-time login code. */
    private function completeCallback(OAuthIdentityModel $identity): string
    {
        $provider = $this->fakeProvider($identity);

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state, 'code' => 'c']);

        return $this->codeFromLocation();
    }

    /** Step 1, over the scheme the flow cookie requires. */
    private function startFlow(): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/google');
    }

    /** @param array<string, string> $query */
    private function requestCallback(array $query): void
    {
        $this->client->request('GET', self::ORIGIN . '/api/auth/oauth/google/callback', $query);
    }

    private function postJson(string $uri, mixed $payload): void
    {
        $this->client->request(
            'POST',
            self::ORIGIN . $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );
    }

    private function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    private function codeFromLocation(): string
    {
        parse_str((string) parse_url($this->location(), \PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /** The code keeps the JWT out of URLs: no `token` parameter, and no three-part JWT under any other name. */
    private function assertNoTokenInLocation(): void
    {
        $location = $this->location();

        self::assertStringNotContainsString('token', $location);
        self::assertDoesNotMatchRegularExpression(
            '/eyJ[\w-]+\.[\w-]+\.[\w-]+/',
            $location,
            'a JWT in a Location header lands in browser history and every proxy log',
        );
    }

    /**
     * Compares against a real unknown-code response, not a fixed shape: any difference would tell a prober the code is
     * live. Fires a request, so it must be the last thing a test does.
     */
    private function assertIndistinguishableFromABadCode(): void
    {
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $contentType = $response->headers->get('content-type');
        $payload = $this->payload();

        self::assertSame(400, $status);
        self::assertArrayNotHasKey('token', $payload);

        $this->postJson('/api/auth/oauth/exchange', ['code' => str_repeat('f', 64)]);

        $unknown = $this->client->getResponse();
        self::assertSame($status, $unknown->getStatusCode());
        self::assertSame($contentType, $unknown->headers->get('content-type'));
        self::assertSame($payload, $this->payload());
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function persistUser(string $email, UserStatus $status): User
    {
        return $this->factory()->create($email, status: $status);
    }

    /**
     * Re-reads through the current entity manager rather than trusting an
     * object a request left in the identity map — the account under test may
     * have been created, claimed or re-statused by the controller.
     */
    private function userByEmail(string $email): User
    {
        $this->entityManager()->clear();

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->entityManager(), $hasher);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }
}
