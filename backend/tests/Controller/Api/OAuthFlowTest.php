<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Dto\OAuth\OAuthIdentity;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Tests\Support\FakeOAuthProvider;
use App\Tests\Support\UserFactory;
use App\Controller\Api\OAuthController;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The OAuth flow driven over HTTP, end to end: redirect out, callback in,
 * one-time code traded for a JWT.
 *
 * Structured after AuthJourneyTest — same KernelBrowser-per-test shape, same
 * rate-limiter clear in setUp, same UserFactory for the fixtures that have no
 * HTTP path, and the same rule that nothing is nudged into place between steps.
 * Each step's precondition is whatever the previous HTTP request left behind.
 *
 * ## Two mechanics that this file cannot work without
 *
 * **`disableReboot()`.** KernelBrowser rebuilds the container after every
 * request by default, which would discard the fake provider registry installed
 * below and silently restore the real one — so a swapped fake would apply to
 * request one and nothing after it. Every flow here spans two or three
 * requests, and the later ones are the interesting ones. This is the same trap
 * a functional test in an earlier task walked into.
 *
 * **Replacing the REGISTRY, not the provider.** `self::getContainer()->set()`
 * accepts either, but the two fail differently and both were tried:
 *
 *  - Setting `GoogleOAuthProvider::class` works only while that service is
 *    still uninstantiated. The registry pulls its providers out of a tagged
 *    iterator lazily, so a swap performed before anything touches the registry
 *    IS picked up — but once a request has built it, the same call throws
 *    `InvalidArgumentException: The "App\Service\OAuth\GoogleOAuthProvider"
 *    service is already initialized, you cannot replace it.` That makes the
 *    seam order-dependent in a way nothing in the test reads.
 *  - Setting `OAuthProviderRegistry::class` replaces the one object the
 *    controller actually asks for, in one hop, with no dependence on whether
 *    the container's tagged iterator collected anything at all.
 *
 * The second is used. The independence is the point: "does the container
 * collect the providers" is OAuthProviderWiringTest's question, and a flow test
 * that answered it too would fail in two files for one cause. Both variants
 * must still run before the first request, because a service the container has
 * already built cannot be replaced either way.
 *
 * **Every request here is made over `https://`,** which is not cosmetic. The
 * flow-binding cookie is `Secure`, and BrowserKit's CookieJar enforces that
 * attribute exactly as a browser does — it will not return a `Secure` cookie to
 * an `http://` URI. Driven over `http://` this suite would still pass its error
 * cases while every happy path lost its cookie, so the binding would look
 * enforced and be untested. Real browsers make `http://localhost` the one
 * exception (verified in Chromium against the exact attribute string this
 * controller sends); BrowserKit does not model that exception, and asking it to
 * would be testing the harness rather than the app.
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

        // The oauth_start limiter counts in the same FILESYSTEM pool as
        // registration and login_throttling, which outlives both the kernel and
        // the run itself. Without this clear the suite passes on a fresh
        // checkout and 429s on the second `composer test`. Same precedent as
        // AuthJourneyTest, RegistrationTest and LoginTest.
        $this->rateLimiterCache()->clear();
    }

    // -- The whole flow ---------------------------------------------------

    /**
     * Redirect to JWT, with the property the whole design exists for asserted
     * in the middle: the callback hands over a code, never a token.
     */
    public function testTheHappyPathTakesAnActiveUserFromRedirectToJwt(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        // 1. Start: we redirect to the provider, carrying a state we minted.
        $this->startFlow();
        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('https://provider.test/authorize', $this->location());

        $state = $provider->lastState;
        self::assertIsString($state);

        // The browser binding rode out with the redirect. Asserted here because
        // "the cookie is never actually stored" is the way this fix breaks
        // silently — every negative test above would still pass, and only the
        // happy path would stop working.
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
        $this->completeCallback(new OAuthIdentity('google', 'sub-new', 'new@example.com', true));

        $user = $this->userByEmail('new@example.com');

        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getPasswordHash());
        self::assertCount(1, $this->em()->getRepository(UserIdentity::class)->findAll());
    }

    /**
     * The other first sign-in: an identity that arrives with no address at all.
     *
     * Apple returns a user's address only on the FIRST authorisation, so
     * somebody who revokes access and comes back has a subject identifier and
     * nothing else — while User::$email is non-nullable and unique. The linker
     * mints a deterministic `<provider>-<hash>@oauth.invalid` placeholder.
     *
     * Asserted through the endpoint on purpose. RegisterRequest refuses the
     * whole reserved `.invalid` TLD, and putting that constraint on the ENTITY
     * instead of on the registration DTO would break exactly this path — every
     * addressless Apple signup — while every unit test of the linker kept
     * passing, because the linker never validates. This is the case that goes
     * red if somebody "tidies" the constraint onto User.
     */
    public function testAnAddresslessIdentityStillGetsAnAccountWithAPlaceholderAddress(): void
    {
        $this->completeCallback(new OAuthIdentity('apple', 'sub-addressless', null, false));

        $identities = $this->em()->getRepository(UserIdentity::class)->findAll();
        self::assertCount(1, $identities);

        $user = $identities[0]->getUser();
        self::assertStringEndsWith('@oauth.invalid', $user->getEmail());
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getPasswordHash());
    }

    // -- The status gate --------------------------------------------------

    /**
     * The reason the status gate lives at exchange and not at callback: a
     * redirect could only say "something went wrong", while this says what the
     * user is actually waiting for — in the same problem+json shape, with the
     * same `type` and the same `accountStatus` key, as POST /api/auth/login
     * returns for the same account (see AuthJourneyTest step 4).
     */
    public function testAPendingApprovalUserGetsAProperExplanationNotAGenericFailure(): void
    {
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-new', 'new@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('account_not_active', $this->payload()['type']);
        self::assertSame('pending_approval', $this->payload()['accountStatus']);
    }

    /**
     * The single most important assertion in this file.
     *
     * OAuthAccountLinker::resolve() deliberately returns a suspended user
     * unchanged — linking proves an address, it does not overrule an admin — so
     * the callback happily issues this account a login code. The status gate in
     * exchange() is the ONLY thing between that code and a working JWT. Delete
     * it and this test is how you find out.
     */
    public function testASuspendedUserCannotExchangeACode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Suspended);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('rejected', $this->payload()['accountStatus']);
    }

    // -- The login code ---------------------------------------------------

    public function testALoginCodeCannotBeUsedTwice(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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

    /**
     * A state value is a credential for a different flow with a different
     * store, and presenting one here must buy nothing. The two stores key on
     * distinct prefixes, so this is really a test that they have not been
     * collapsed into one pool by a later refactor.
     */
    public function testAStateValueIsNotAcceptedAsALoginCode(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->postJson('/api/auth/oauth/exchange', ['code' => $state]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The account was deleted between the callback and the exchange. The code
     * is still live and still names a user id; there is simply nobody to sign
     * in as, and the answer must be the one a bad code gets rather than a 500
     * from a null user.
     */
    public function testACodeForAnAccountDeletedBeforeTheExchangeIsRejected(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        // user_identity's FK carries ON DELETE CASCADE, so removing the user
        // takes the identity row with it, exactly as an account purge would.
        $em = $this->em();
        $em->remove($this->userByEmail('bob@example.com'));
        $em->flush();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayNotHasKey('token', $this->payload());
    }

    /**
     * Expiry is NOT driven here, and deliberately so.
     *
     * The 30-second window is enforced against the injected ClockInterface,
     * which in this environment is the real clock — nothing in the test
     * container swaps a MockClock in, and introducing one for a single case
     * would change the clock every other functional test runs on. The window is
     * pinned instead in LoginCodeStoreTest, which drives a MockClock across the
     * boundary from both sides (+29 s accepted, +31 s refused) and additionally
     * proves a read does not extend it. There is no code path between that
     * store and this endpoint that could honour the TTL differently: exchange()
     * does nothing but call consume().
     */

    // -- The flow is bound to the browser that started it ------------------

    /**
     * THE LOGIN-CSRF REGRESSION TEST. This is the one that was missing.
     *
     * `state` alone proves "this server started some flow". It does not prove
     * "this browser started this flow", and without the second property the
     * callback accepts a state and code from anyone holding them.
     *
     * The attack that exploits the gap: an attacker with a real account scripts
     * the start endpoint, keeps `state`, completes the consent screen at the
     * provider, and captures `code` from the provider's final 302 WITHOUT
     * following it — so the state is never burned. They then get a victim to
     * open the callback URL. Per the SPA contract the exchange fires with no
     * user gesture, and the victim's browser is now signed in AS THE ATTACKER:
     * every feed they add and every article they read lands in the attacker's
     * account.
     *
     * Clearing the cookie jar is exactly that attacker — the state and code are
     * genuine and unspent, and the only thing missing is the browser that
     * started the flow.
     */
    public function testACallbackFromABrowserThatDidNotStartTheFlowIsRefused(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        // Everything the attacker cannot carry across to the victim's browser.
        $this->client->getCookieJar()->clear();

        $this->requestCallback(['state' => $state, 'code' => 'provider-code']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=invalid_state', $this->location());

        // The two assertions that make this a security test rather than a
        // string comparison: no login code was minted, and the provider was
        // never spoken to — so the attacker's authorization code was not spent
        // against the victim's browser.
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
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->replaceFlowCookie(str_repeat('a', 64));

        $this->requestCallback(['state' => $state, 'code' => 'provider-code']);

        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertStringNotContainsString('code=', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    /**
     * A wrong cookie must not merely fail — it must BURN the state, so the
     * comparison cannot be brute-forced by retrying the same live state with a
     * different guess each time. The store deletes before it validates, and
     * this is the endpoint-level proof of that ordering.
     *
     * Without it, a 64-hex-character binding would still be unguessable, but
     * the property would rest on the search space alone rather than on the flow
     * being single-use.
     */
    public function testAFailedBindingCheckBurnsTheStateSoItCannotBeRetried(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
     * The attributes, asserted on the wire rather than read off the source.
     *
     * `SameSite=None` is the one that looks wrong and is not. Apple returns its
     * callback as a CROSS-SITE POST (`response_mode=form_post`), and a `Lax`
     * cookie is not sent on a cross-site POST — so `Lax` would leave Google
     * working perfectly and Apple failing every sign-in with `invalid_state`,
     * which is the worst kind of bug to diagnose. `None` requires `Secure`.
     *
     * `__Host-` is what stops the binding being pinned: the prefix forbids a
     * `Domain` attribute, so a sibling or parent host cannot write this cookie
     * into the backend's origin even if one of them is compromised.
     */
    public function testTheFlowCookieCarriesTheAttributesTheCrossSitePostNeeds(): void
    {
        $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();

        $cookie = $this->responseCookie(OAuthController::FLOW_COOKIE);

        self::assertTrue($cookie->isSecure(), 'SameSite=None is only honoured on a Secure cookie');
        self::assertTrue($cookie->isHttpOnly(), 'no script needs to read the flow binding');
        self::assertSame('none', $cookie->getSameSite(), "Apple's cross-site POST would not carry a Lax cookie");

        // The two the __Host- prefix mandates, and which browsers enforce by
        // rejecting the cookie outright if they are wrong.
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());

        // Bound to the flow's ten minutes PLUS the login code's thirty seconds,
        // not to a browser session. The extra half-minute is what lets a
        // callback arriving in the final second of the state's life still hand
        // the SPA a code the browser can actually exchange.
        self::assertEqualsWithDelta(630, $cookie->getExpiresTime() - time(), 5);
    }

    /**
     * The binding carries nothing about the user, because it is set before
     * anyone is authenticated and on an endpoint a stranger can hit.
     *
     * A value derived from an address, an id or an IP would turn an
     * unauthenticated endpoint into a tracking beacon that outlives the flow.
     * This one is opaque random bytes and means nothing without the server-side
     * entry it is hashed against.
     */
    public function testTheFlowCookieRevealsNothingAndIsClearedWhenTheFlowFails(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();

        $value = $this->flowCookieValue();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
        self::assertStringNotContainsString('bob', $value);

        // Two flows never share a binding, so it cannot correlate visits.
        $this->startFlow();
        self::assertNotSame($value, $this->flowCookieValue());

        // A failing callback ends the flow then and there, so the binding goes
        // with it. The SUCCESS exit deliberately does not clear — the code it
        // just minted is bound to this same cookie, and the exchange needs it
        // back. See testTheBindingOutlivesTheCallbackAndDiesAtTheExchange.
        $this->requestCallback(['state' => 'not-a-state', 'code' => 'c']);
        self::assertNull(
            $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost'),
            'a failed flow must not leave its binding in the browser',
        );
    }

    // -- The login code is bound to the same browser -----------------------

    /**
     * THE SECOND HALF OF THE LOGIN CSRF, and the reason binding the callback
     * was not on its own enough.
     *
     * Binding the flow forces the attacker to complete it in their OWN browser.
     * That is not a dead end for them, because the login code that falls out of
     * it was, until this fix, a pure bearer value: whoever POSTs it to
     * `/exchange` gets the JWT. So the attacker runs a genuine sign-in end to
     * end, does NOT redeem the code, and inside its 30-second life points a
     * victim at `<frontend>/auth/callback?code=X`. Per section 7.3 the SPA
     * exchanges on landing with no gesture, and the victim holds the attacker's
     * token — the same outcome as before, narrowed to 30 seconds and fully
     * scriptable.
     *
     * Clearing the jar is that victim: the code is genuine, unspent and inside
     * its window, and the only thing missing is the browser that earned it.
     */
    public function testALoginCodeCannotBeExchangedByADifferentBrowser(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        // Everything the attacker cannot carry across to the victim's browser.
        $this->client->getCookieJar()->clear();

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);

        $this->assertIndistinguishableFromABadCode();
    }

    /**
     * The other way to arrive without the binding, and the one that decides
     * whether the fix is real: a cookie that is present and well formed but
     * belongs to a different flow. This reaches the hash_equals comparison
     * rather than short-circuiting on an absent cookie, so it proves the check
     * is a comparison and not merely an isset().
     */
    public function testALoginCodeCannotBeExchangedWithSomebodyElsesBinding(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));
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
     * The lifetime, from both ends: the binding must survive the callback —
     * which is where it used to be cleared — and must not survive the exchange.
     *
     * Both halves break silently in opposite directions. Clear too early and
     * every sign-in fails with what looks like a bad code; clear never, and an
     * unauthenticated endpoint has left a durable value in the browser for no
     * reason, which is the shape of a tracking cookie even when the contents
     * are meaningless.
     */
    public function testTheBindingOutlivesTheCallbackAndDiesAtTheExchange(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $code = $this->completeCallback(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        // Survived the callback, or the happy path below could not work.
        self::assertNotSame('', $this->flowCookieValue());

        $this->postJson('/api/auth/oauth/exchange', ['code' => $code]);
        self::assertResponseIsSuccessful();

        self::assertNull(
            $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost'),
            'the binding has no purpose once the code it binds has been spent',
        );
    }

    // -- The callback -----------------------------------------------------

    public function testAStateValueCannotBeReplayed(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('code=', $this->location());

        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('error=invalid_state', $this->location());
        $this->assertNoTokenInLocation();
    }

    /**
     * A state issued for Google, replayed at Apple's callback.
     *
     * Without the provider comparison this would spend a Google authorization
     * code, carrying a Google nonce, against Apple's token endpoint — and would
     * let whoever chose the URL decide which provider's answer is trusted for a
     * flow they did not start. `apple` is used precisely because it is NOT
     * configured in this environment: the check must fire before the registry
     * is consulted, so a mismatch cannot be masked by a 404 that would look
     * like a pass.
     */
    public function testAStateIssuedForOneProviderIsRefusedAtAnothersCallback(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->startFlow();
        $state = (string) $provider->lastState;

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
        $this->requestCallback(['state' => $state, 'code' => 'c']);
        self::assertStringContainsString('error=invalid_state', $this->location());
    }

    /**
     * A stolen authorization code arriving with no state at all. This is the
     * attack the state value exists to stop, and the refusal must happen before
     * the provider is spoken to — otherwise the attacker's code has already
     * been redeemed against somebody's browser.
     *
     * The reason code is `invalid_request`, not `invalid_state`: the
     * missing-parameter check runs before the store is consulted, so a callback
     * with no state never reaches the state comparison. (The plan asserted
     * `invalid_state` here while specifying a controller that cannot produce
     * it — the two halves contradicted each other. The controller's ordering is
     * the correct half: there is no state to call invalid.)
     *
     * Nothing is disclosed by telling these apart. Whether the caller sent a
     * `state` parameter is something the caller already knows; the codes that
     * would matter — "expired" versus "already used" versus "never issued" —
     * are the ones OAuthStateStore::consume() deliberately collapses into one
     * null, and all three still arrive here as `invalid_state`.
     */
    public function testACallbackWithNoStateIsRefusedWithoutContactingTheProvider(): void
    {
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $this->requestCallback(['state' => str_repeat('f', 64), 'code' => 'a-stolen-code']);

        self::assertStringContainsString('error=invalid_state', $this->location());
        self::assertSame([], $provider->exchanges);
    }

    public function testACallbackWithNoCodeIsRefusedAsAnInvalidRequest(): void
    {
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
     * The callback is the one endpoint that sets a Location header from inside
     * a flow a stranger can trigger, and the success path attaches a fresh
     * login code to it. An open redirect here would therefore not merely send
     * a browser somewhere — it would hand the attacker's page a live
     * credential.
     *
     * So: nothing the caller supplied may reach that header. The host comes
     * from APP_FRONTEND_URL, a deployment-time value; the reason is one of four
     * literals in the controller. This drives the whole caller-controlled
     * surface — the provider's own `error` value, plus `state` and `code` — at
     * a redirect and pins the result to the configured origin.
     */
    public function testNothingTheCallerSuppliesCanReachTheLocationHeader(): void
    {
        $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        $hostile = 'https://evil.test/steal';

        foreach ([['error' => $hostile], ['state' => $hostile, 'code' => $hostile]] as $query) {
            $this->requestCallback($query);

            self::assertResponseStatusCodeSame(302);
            self::assertStringStartsWith('http://localhost:4200/auth/callback?error=', $this->location());
            self::assertStringNotContainsString('evil.test', $this->location());
            $this->assertNoTokenInLocation();
        }
    }

    /**
     * Apple returns its callback as a cross-site form POST, so the parameters
     * arrive in a body rather than a query string. Driven through Google's
     * callback because the fake is the only provider this suite can complete a
     * flow with; what is under test is the controller's parameter reading, not
     * anything Apple-specific.
     */
    public function testACallbackPostedAsAFormBodyCompletesTheSameWay(): void
    {
        $this->persistUser('bob@example.com', UserStatus::Active);
        $provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

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
        $provider = $this->fakeProvider(
            new OAuthIdentity('google', 'sub-1', null, false),
            failExchange: true,
        );

        $this->startFlow();
        $state = (string) $provider->lastState;

        $this->requestCallback(['state' => $state, 'code' => 'c']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('error=exchange_failed', $this->location());
        $this->assertNoTokenInLocation();
        self::assertStringNotContainsString('fake provider was told to fail', $this->location());
    }

    // -- The public surface -----------------------------------------------

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
     * The oauth_start limiter, actually fired.
     *
     * Worth a real test rather than a reading of the config: this is the one
     * limiter whose factory is injected by name, and a renamed or missing
     * `oauth_start` block would autowire a DIFFERENT limiter's factory without
     * any error — the endpoint would still work, and would simply be capped by
     * somebody else's budget or not at all.
     *
     * 20 requests are accepted and the 21st is not, so both edges of the
     * configured limit are pinned. The count also proves setUp()'s clear
     * actually empties the pool: on a dirty pool the first request here would
     * already be over budget.
     */
    public function testTheStartLimiterRefusesTheTwentyFirstAttempt(): void
    {
        $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));

        for ($i = 1; $i <= 20; ++$i) {
            $this->startFlow();
            self::assertResponseStatusCodeSame(302, "start attempt {$i} should be within budget");
        }

        $this->startFlow();

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('rate_limited', $this->payload()['type']);
        self::assertNotSame('0', $this->client->getResponse()->headers->get('Retry-After'));
    }

    // -- helpers ----------------------------------------------------------

    /**
     * Installs a fake provider by replacing the whole registry. MUST be called
     * before the first request of a test — see the class docblock for why, and
     * for why the provider service itself is not the seam.
     */
    private function fakeProvider(OAuthIdentity $identity, bool $failExchange = false): FakeOAuthProvider
    {
        $provider = new FakeOAuthProvider($identity, $failExchange);

        self::getContainer()->set(
            OAuthProviderRegistry::class,
            new OAuthProviderRegistry([$provider]),
        );

        return $provider;
    }

    // -- The flow cookie --------------------------------------------------

    /**
     * The binding value the browser is currently holding.
     *
     * Read from the JAR, not from the response, so it asserts what a browser
     * would actually send back — a cookie the jar rejected (wrong scheme, wrong
     * path) would be invisible here, which is the failure worth catching.
     */
    private function flowCookieValue(): string
    {
        $cookie = $this->client->getCookieJar()->get(OAuthController::FLOW_COOKIE, '/', 'localhost');
        self::assertNotNull($cookie, 'the flow never set a binding cookie');

        return $cookie->getValue();
    }

    /**
     * Puts a binding of our choosing in the jar.
     *
     * The attributes are restated rather than copied off whatever is currently
     * stored, because the callback CLEARS the cookie — so after one failed
     * attempt there is nothing left to copy from. They must match what the
     * controller sends, or the jar withholds the cookie for an unrelated reason
     * and the test would pass without ever reaching the comparison.
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
    private function completeCallback(OAuthIdentity $identity): string
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

    // -- Reading the response ---------------------------------------------

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

    /**
     * The one-time code exists solely to keep the JWT out of URLs, so every
     * path that sets a Location — success and failure alike — is checked for
     * one. `token` catches a query parameter added by name; the three-part
     * pattern catches a bare JWT smuggled in under any other name.
     */
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
     * Asserts the response just received is not merely a 400, but the SAME 400
     * an unknown code gets — status, content type and body alike.
     *
     * "Indistinguishable" is the actual requirement, so it is compared against
     * a real unknown-code response rather than against a hard-coded shape. A
     * distinct error type, a different `detail`, even a different casing would
     * let a prober tell "this code is live but not yours" from "no such code",
     * and that distinction is exactly what confirms a captured code is worth
     * replaying.
     *
     * Fires a second request, so it must be the last thing a test does.
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

    // -- Fixtures ---------------------------------------------------------

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
        $this->em()->clear();

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

        return new UserFactory($this->em(), $hasher);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function rateLimiterCache(): CacheItemPoolInterface
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');

        return $cache;
    }
}
