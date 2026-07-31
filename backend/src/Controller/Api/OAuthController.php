<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\OAuth\OAuthExchangeRequest;
use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\CallbackParameters;
use App\Service\OAuth\FlowCookie;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthRedirectFactory;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Random\RandomException;
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
 * What this class does NOT decide: what the login code is bound to, which
 * failures are indistinguishable from one another, and where the account-status
 * gate sits. Those are one rule set spanning both legs and they live in
 * {@see OAuthSignIn}. What is left here is the HTTP around them — reading the
 * binding cookie off the request, setting and clearing it, and choosing between
 * a redirect and problem+json.
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
     * The name of the browser-binding cookie, re-exported from the collaborator
     * that owns its whole lifecycle. Kept public because OAuthFlowTest asserts
     * against it; see {@see FlowCookie::NAME} for why the `__Host-` prefix and
     * every other attribute are load-bearing.
     */
    public const string FLOW_COOKIE = FlowCookie::NAME;

    /**
     * Bounds the `{provider}` segment to something that could plausibly name a
     * provider. See the class docblock for what this does and does not fix.
     */
    private const string PROVIDER_PATTERN = '[a-z][a-z0-9_-]{1,31}';

    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly OAuthStateStore $stateStore,
        private readonly OAuthSignIn $signIn,
        private readonly LoggerInterface $logger,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $oauthStartLimiter,
        private readonly FlowCookie $flowCookie,
        private readonly OAuthRedirectFactory $oauthRedirect,
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
     *
     * THIS IS A CREDENTIALED CROSS-ORIGIN REQUEST. The SPA must send it with
     * `credentials: 'include'`, because the login code is only half of what is
     * needed — the flow cookie is the other half. A caller that forgets gets a
     * 400 identical to a bad code, which is the single most confusing failure
     * this design has; docs/oauth-sign-in.md section 7.3 says so in as many
     * words. CorsListener is what allows the cookie to ride along.
     */
    #[Route('/exchange', name: 'api_auth_oauth_exchange', methods: ['POST'])]
    public function exchange(
        Request $httpRequest,
        #[MapRequestPayload] OAuthExchangeRequest $request,
    ): JsonResponse {
        // The binding, read straight off the request. `null` when the browser
        // sent none — which the store treats as a failure, not as a reason to
        // skip the check.
        $browserToken = $httpRequest->cookies->get(self::FLOW_COOKIE);

        $token = $this->signIn->redeemLoginCode(
            $request->code,
            \is_string($browserToken) ? $browserToken : null,
        );

        // The code is spent and the session has begun, so the binding has
        // nothing left to bind. Not cleared when redeeming throws: the response
        // is then the exception listener's rather than ours — and the cookie
        // expires with the flow's ten minutes regardless.
        return $this->flowCookie->clearFrom(new JsonResponse(['token' => $token]));
    }

    /**
     * Step 2: the provider sends the browser back.
     * GET and POST. Google returns a GET with a query string; Apple returns a
     * cross-site POST with a form body, because we request a scope and Apple
     * then requires `response_mode=form_post`.
     * Every failure below leaves as a redirect to the SPA carrying an error
     * code, never as problem+json. The caller here is a browser following a
     * redirect chain — a JSON error body would be a dead end showing raw JSON
     * in the address bar instead of a login page saying what went wrong.
     *
     * @throws InvalidArgumentException
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
        if (null !== CallbackParameters::read($request, 'error')) {
            return $this->oauthRedirect->failure('access_denied');
        }

        $state = CallbackParameters::read($request, 'state');
        $code = CallbackParameters::read($request, 'code');

        if (null === $state || null === $code) {
            return $this->oauthRedirect->failure('invalid_request');
        }

        // The binding cookie, read straight off the request. `null` when the
        // browser sent none — which the store treats as a failure, not as a
        // reason to skip the check.
        $cookie = $request->cookies->get(self::FLOW_COOKIE);
        $browserToken = \is_string($cookie) ? $cookie : null;
        $started = $this->stateStore->consume($state, $browserToken);

        // No valid state means this callback was not started by this server,
        // was already used, is older than ten minutes, or — the case `state`
        // alone could never catch — was started by a DIFFERENT BROWSER. All
        // four are discarded without touching the provider.
        //
        // That fourth case is login CSRF, and it is the reason the binding
        // exists. An attacker who legitimately obtains a state and a code from
        // their own account, and gets a victim to open this URL, would
        // otherwise sign the victim's browser in as themselves — silently,
        // because the SPA exchanges the code with no user gesture. It is one
        // reason code for all four on purpose: a caller who could tell them
        // apart could probe for live states.
        //
        // The provider comparison is not decoration. A state issued for Google
        // replayed at Apple's callback would otherwise spend a Google
        // authorization code, with a Google nonce, against Apple's token
        // endpoint — and, worse, would let whoever chose the URL decide which
        // provider's answer is trusted for a flow they did not start.
        if (null === $started || $started->provider !== $provider) {
            return $this->oauthRedirect->failure('invalid_state');
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

            return $this->oauthRedirect->failure('exchange_failed');
        }

        // consume() above refuses a null token outright, so reaching this line
        // proves the cookie was present and matched. Restated for the type
        // checker, and because issuing the code depends on it being a string.
        \assert(null !== $browserToken);

        // The flow cookie is NOT cleared here, unlike every failure exit. The
        // code minted below is bound to this same value and the exchange needs
        // it back one hop later; clearing here would make every sign-in fail
        // with what looks exactly like a bad code. exchange() clears it instead,
        // once the code has been spent and the binding has nothing left to bind.
        //
        // Note that a suspended or pending user reaches this line too, and
        // leaves with a working code — see OAuthSignIn::issueLoginCode() for why
        // the status gate deliberately sits at the exchange instead.
        return $this->oauthRedirect->success($this->signIn->issueLoginCode($identity, $browserToken));
    }

    /**
     * Step 1: send the browser to the provider.
     *
     * Declared LAST: `/{provider}` is the catch-all of this controller and
     * would shadow every literal route above it. See the class docblock.
     *
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    #[Route(
        '/{provider}',
        name: 'api_auth_oauth_start',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET'],
    )]
    public function start(string $provider, Request $request): RedirectResponse
    {
        // Only start() is capped. The callback cannot be replayed (its state is
        // single-use) and the exchange cannot be guessed (a 32-byte code that
        // lives 30 seconds), so a limiter on either would spend cache writes
        // defending something already closed — while a limiter on start() bounds
        // a scripted redirect loop that would otherwise fill the state pool for
        // free.
        $this->rateLimitGuard->enforceForClient($this->oauthStartLimiter, $request);

        // Throws UnknownProviderException (404 problem+json) for a name this
        // deployment does not offer. That is the right shape here: nothing has
        // been redirected yet, so the caller is either the SPA or a probe.
        $oauthProvider = $this->providers->get($provider);

        $state = $this->stateStore->start($provider);

        $response = new RedirectResponse($oauthProvider->getAuthorizationUrl(
            $state->state,
            $state->nonce,
            $state->codeChallenge,
        ));

        // The browser binding rides out with the redirect. Without it `state`
        // would prove only that THIS SERVER started some flow, and anyone
        // holding a state and a code — including the attacker who obtained both
        // legitimately from their own account — could spend them in somebody
        // else's browser. See OAuthStateStore's docblock for the full attack.
        \assert(null !== $state->browserToken);
        $response->headers->setCookie($this->flowCookie->issue($state->browserToken));

        return $response;
    }
}
