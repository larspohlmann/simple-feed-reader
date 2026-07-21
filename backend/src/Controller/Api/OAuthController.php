<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\OAuth\OAuthExchangeRequest;
use App\Entity\User;
use App\Exception\AccountNotActiveException;
use App\Exception\InvalidTokenException;
use App\Exception\OAuth\OAuthFailedException;
use App\Exception\RateLimitedException;
use App\Repository\UserRepository;
use App\Security\AccountStatusException;
use App\Security\LoginUserChecker;
use App\Service\OAuth\LoginCodeStore;
use App\Service\OAuth\OAuthAccountLinker;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthStateStore;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The three-legged sign-in: redirect out, callback in, code for token.
 *
 * The shape worth understanding before changing anything here is that the
 * callback NEVER hands the SPA a JWT. It redirects with a one-time 30-second
 * login code, and the SPA POSTs that code back to `/exchange` to get the token.
 * A JWT in a redirect's query string would be written to browser history, sent
 * onward in `Referer` headers and logged verbatim by every proxy in between;
 * the code is worthless 30 seconds later and worthless after one use.
 *
 * ROUTE ORDER IS LOAD-BEARING. `/{provider}` would happily match `providers`
 * and `exchange` too, so the two literal routes are declared FIRST — Symfony
 * matches in declaration order within a controller. Verified rather than
 * assumed, with `php bin/console router:match`:
 *
 *   /api/auth/oauth/providers        GET      -> api_auth_oauth_providers
 *   /api/auth/oauth/exchange         POST     -> api_auth_oauth_exchange
 *   /api/auth/oauth/google           GET      -> api_auth_oauth_start
 *   /api/auth/oauth/google/callback  GET|POST -> api_auth_oauth_callback
 *
 * One resolution is worth naming because it looks like a bug and is not:
 * `GET /api/auth/oauth/exchange` falls through to start() with
 * `provider=exchange`, since the literal exchange route is POST-only. The
 * registry has no provider by that name, so it answers 404 problem+json —
 * which is the correct answer to a GET on a POST-only endpoint's URL under a
 * catch-all, and discloses nothing.
 *
 * The `{provider}` requirement below is a second, independent belt: it bounds
 * the segment to a plausible provider name so a path traversal or a 4 KB
 * segment never reaches the registry. It does NOT resolve the `providers`
 * collision on its own — `providers` matches that pattern perfectly well — so
 * do not reorder these methods on the strength of it.
 */
#[Route('/api/auth/oauth')]
final class OAuthController
{
    /**
     * Bounds the `{provider}` segment to something that could plausibly name a
     * provider. See the class docblock for what this does and does not fix.
     */
    private const PROVIDER_PATTERN = '[a-z][a-z0-9_-]{1,31}';

    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly OAuthStateStore $stateStore,
        private readonly LoginCodeStore $loginCodes,
        private readonly OAuthAccountLinker $linker,
        private readonly UserRepository $users,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly LoginUserChecker $loginUserChecker,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $oauthStartLimiter,
        #[Autowire('%env(APP_FRONTEND_URL)%')] private readonly string $frontendUrl,
    ) {
    }

    /**
     * Which providers this deployment can actually complete a sign-in with.
     *
     * Exists so the SPA does not render an Apple button on an instance with no
     * Apple credentials. Public and unauthenticated by necessity — it is read
     * before anyone has logged in — and it reveals only which public sign-in
     * options the login page was going to show anyway.
     */
    #[Route('/providers', name: 'api_auth_oauth_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return new JsonResponse(['providers' => $this->providers->getConfiguredNames()]);
    }

    /**
     * Step 3: the SPA trades the one-time code for the JWT.
     *
     * A POST, so the credential travels in a body rather than a URL that would
     * be written to browser history, sent onward in Referer headers and logged
     * by every proxy in between. That is the entire reason this endpoint exists
     * instead of the callback redirecting with a token.
     *
     * Declared above start() purely for route ordering — see the class
     * docblock. The methods differ (POST vs GET), so today a misordering would
     * not actually mis-resolve; relying on that would make the correctness of
     * this URL depend on start() never gaining a POST.
     */
    #[Route('/exchange', name: 'api_auth_oauth_exchange', methods: ['POST'])]
    public function exchange(#[MapRequestPayload] OAuthExchangeRequest $request): JsonResponse
    {
        $userId = $this->loginCodes->consume($request->code);

        if (null === $userId) {
            throw new InvalidTokenException();
        }

        $user = $this->users->find($userId);

        // The account was deleted, or purged, between the callback and this
        // request. Same answer as a bad code — there is nothing to sign in as,
        // and the two must not be distinguishable.
        if (!$user instanceof User) {
            throw new InvalidTokenException();
        }

        $this->assertMayLogIn($user);

        return new JsonResponse(['token' => $this->jwtManager->create($user)]);
    }

    /**
     * Step 2: the provider sends the browser back.
     *
     * GET and POST. Google returns a GET with a query string; Apple returns a
     * cross-site POST with a form body, because we request a scope and Apple
     * then requires `response_mode=form_post`.
     *
     * Every failure below leaves as a redirect to the SPA carrying an error
     * code, never as problem+json. The caller here is a browser following a
     * redirect chain — a JSON error body would be a dead end showing raw JSON
     * in the address bar instead of a login page saying what went wrong.
     */
    #[Route(
        '/{provider}/callback',
        name: 'api_auth_oauth_callback',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function callback(string $provider, Request $request): RedirectResponse
    {
        // Apple and Google both report a declined consent screen this way. It
        // is the single most common non-success outcome and is not an error.
        if (null !== self::param($request, 'error')) {
            return $this->failure('access_denied');
        }

        $state = self::param($request, 'state');
        $code = self::param($request, 'code');

        if (null === $state || null === $code) {
            return $this->failure('invalid_request');
        }

        $started = $this->stateStore->consume($state);

        // No valid state means this callback was not started by this server,
        // was already used, or is older than ten minutes. All three are
        // discarded without touching the provider — this check is what stops a
        // stranger's authorization code being fed into somebody's browser.
        //
        // The provider comparison is not decoration. A state issued for Google
        // replayed at Apple's callback would otherwise spend a Google
        // authorization code, with a Google nonce, against Apple's token
        // endpoint — and, worse, would let whoever chose the URL decide which
        // provider's answer is trusted for a flow they did not start.
        if (null === $started || $started->provider !== $provider) {
            return $this->failure('invalid_state');
        }

        try {
            $identity = $this->providers->get($provider)
                ->exchangeCode($code, $started->codeVerifier, $started->nonce);
        } catch (OAuthFailedException $e) {
            // The detail is for us. The user gets a code they can quote.
            $this->logger->warning('OAuth exchange failed', [
                'provider' => $provider,
                'detail' => $e->logDetail,
                'exception' => $e->getPrevious(),
            ]);

            return $this->failure('exchange_failed');
        }

        $user = $this->linker->resolve($identity);

        // Deliberately NOT gated on status here. A pending_approval or
        // suspended user still receives a login code and still exchanges it —
        // and exchange() is where the status check lives, so that the SPA gets
        // a proper problem+json explaining WHY it cannot sign in, exactly as
        // the password login does. Refusing here would collapse "you are
        // waiting for approval" into a generic redirect error.
        //
        // The login code is worth nothing on its own: it names a user id, and
        // exchange() re-reads that user and re-runs the status gate before any
        // token is minted.
        $userId = $user->getId();
        \assert(null !== $userId);

        return new RedirectResponse(\sprintf(
            '%s/auth/callback?code=%s',
            $this->frontendBaseUrl(),
            urlencode($this->loginCodes->issue($userId)),
        ));
    }

    /**
     * Step 1: send the browser to the provider.
     *
     * Declared LAST: `/{provider}` is the catch-all of this controller and
     * would shadow every literal route above it. See the class docblock.
     */
    #[Route(
        '/{provider}',
        name: 'api_auth_oauth_start',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET'],
    )]
    public function start(string $provider, Request $request): RedirectResponse
    {
        $this->enforceStartLimit($request);

        // Throws UnknownProviderException (404 problem+json) for a name this
        // deployment does not offer. That is the right shape here: nothing has
        // been redirected yet, so the caller is either the SPA or a probe.
        $oauthProvider = $this->providers->get($provider);

        $state = $this->stateStore->start($provider);

        return new RedirectResponse($oauthProvider->getAuthorizationUrl(
            $state->state,
            $state->nonce,
            $state->codeChallenge,
        ));
    }

    /**
     * The status gate, and the ONLY thing between a suspended account and a
     * working JWT on this path.
     *
     * OAuthAccountLinker::resolve() deliberately returns suspended and rejected
     * users unchanged — linking proves an address, it does not overrule an
     * admin — so nothing earlier in this flow refuses them. Delete this call
     * and a suspended user signs in through OAuth.
     *
     * LoginUserChecker::checkPostAuth() is called rather than the rule being
     * restated, so a future status change is made in exactly one place and both
     * login paths follow it. The translation below is the same one
     * LoginFailureHandler performs for the password login: the security layer's
     * AccountStatusException is an AuthenticationException, which the API
     * exception listener renders as a bare 401 "unauthorized" — correct for a
     * stolen JWT, wrong here, where the user has just proved an identity and is
     * owed the reason.
     *
     * The plan suggested teaching the listener to map AccountStatusException
     * globally instead. That was rejected on purpose: the `api` firewall's
     * UserChecker throws the SAME exception for a suspended holder of a live
     * token, and JwtAccessTest::testSuspendedTokenDoesNotLeakAccountStatus
     * pins that path to a 401 disclosing nothing. A global mapping would put a
     * status-disclosing 403 one listener-priority change away from that path.
     * Disclosure is decided at the endpoint that verified an identity, which is
     * exactly where LoginFailureHandler decides it too.
     */
    private function assertMayLogIn(User $user): void
    {
        try {
            $this->loginUserChecker->checkPostAuth($user);
        } catch (AccountStatusException $e) {
            throw new AccountNotActiveException($e->accountStatus);
        }
    }

    /**
     * A redirect back to the SPA carrying a reason code instead of a session.
     *
     * Note what does not appear in the URL, on this path or the success path: a
     * JWT, an authorization code, a state value, or anything else the caller
     * supplied. `$reason` is one of a fixed set of literals in this file, and
     * the host comes from APP_FRONTEND_URL — a deployment-time value nobody can
     * influence over HTTP. That is what keeps this from being an open redirect
     * that hands the attacker's page a fresh login code.
     */
    private function failure(string $reason): RedirectResponse
    {
        return new RedirectResponse(\sprintf(
            '%s/auth/callback?error=%s',
            $this->frontendBaseUrl(),
            urlencode($reason),
        ));
    }

    private function frontendBaseUrl(): string
    {
        return rtrim($this->frontendUrl, '/');
    }

    /**
     * Reads a callback parameter from the query string or the form body, and
     * from nowhere else.
     *
     * Explicitly NOT Request::get(): that also searches the request attributes,
     * which is where the router puts `{provider}` and `_route`. A callback
     * parameter must come from the provider, not from the routing table, and a
     * reader that can silently fall back to an attribute is one added route
     * placeholder away from surprising. Blank is treated as absent, so `?code=`
     * cannot pass a non-empty-string check by being a string.
     */
    private static function param(Request $request, string $name): ?string
    {
        $value = $request->query->get($name) ?? $request->request->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Mirrors AuthController::enforceLimit(). See its docblock for why the
     * limit is applied before anything else, and for what the key is and is not
     * once this app sits behind a proxy.
     *
     * Only start() is capped. The callback cannot be replayed (its state is
     * single-use) and the exchange cannot be guessed (a 32-byte code that lives
     * 30 seconds), so a limiter on either would spend cache writes defending
     * something already closed — while a limiter on start() bounds a scripted
     * redirect loop that would otherwise fill the state pool for free.
     */
    private function enforceStartLimit(Request $request): void
    {
        $limit = $this->oauthStartLimiter->create($request->getClientIp())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(max(
            1,
            $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        ));
    }
}
