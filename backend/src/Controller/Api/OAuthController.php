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
 * The callback NEVER hands the SPA a JWT. It redirects with a one-time
 * 30-second login code that the SPA POSTs to `/exchange` for the token: a JWT
 * in a redirect URL would leak via history, Referer and proxy logs, while the
 * code is worthless after 30 seconds or one use.
 *
 * What the code is bound to, which failures are indistinguishable, and where
 * the account-status gate sits is one rule set spanning both legs; it lives in
 * {@see OAuthSignIn}. Left here is the HTTP around it — reading, setting and
 * clearing the binding cookie, and choosing redirect vs problem+json.
 *
 * ROUTE ORDER IS LOAD-BEARING. `/{provider}` would match `providers` and
 * `exchange` too, so the literal routes are declared FIRST (Symfony matches in
 * declaration order). Verified with `php bin/console router:match`:
 *
 *   /api/auth/oauth/providers        GET      -> api_auth_oauth_providers
 *   /api/auth/oauth/exchange         POST     -> api_auth_oauth_exchange
 *   /api/auth/oauth/google           GET      -> api_auth_oauth_start
 *   /api/auth/oauth/google/callback  GET|POST -> api_auth_oauth_callback
 *
 * `GET /api/auth/oauth/exchange` falls through to start() with
 * `provider=exchange` (the literal route is POST-only); the registry has no
 * such provider and answers 404 problem+json, correct and disclosing nothing.
 * The `{provider}` requirement is a second belt bounding the segment to a
 * plausible name, but it does NOT resolve the `providers` collision alone — so
 * do not reorder these methods.
 */
#[Route('/api/auth/oauth')]
final class OAuthController
{
    /**
     * Browser-binding cookie name, re-exported from the collaborator that owns
     * its lifecycle. Public because OAuthFlowTest asserts against it; see
     * {@see FlowCookie::NAME} for why the `__Host-` prefix and attributes matter.
     */
    public const string FLOW_COOKIE = FlowCookie::NAME;

    /**
     * Bounds the `{provider}` segment to a plausible provider name. See the class
     * docblock for what this does and does not fix.
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
     * Which providers this deployment can complete a sign-in with, so the SPA
     * does not render an Apple button on an instance with no Apple credentials.
     * Unauthenticated by necessity (read before login) and reveals only the
     * sign-in options the login page would show anyway.
     */
    #[Route('/providers', name: 'api_auth_oauth_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return new JsonResponse(['providers' => $this->providers->getConfiguredNames()]);
    }

    /**
     * Step 3: the SPA trades the one-time code for the JWT. A POST so the
     * credential travels in a body, not a URL leaked via history, Referer and
     * proxy logs — the whole reason this exists instead of the callback
     * redirecting with a token.
     *
     * Declared above start() for route ordering (see class docblock). The methods
     * differ (POST vs GET) so a misordering would not mis-resolve today, but
     * relying on that ties this URL's correctness to start() never gaining a POST.
     *
     * THIS IS A CREDENTIALED CROSS-ORIGIN REQUEST: the SPA must send it with
     * `credentials: 'include'`, because the flow cookie is the other half of the
     * login code. A caller that forgets gets a 400 identical to a bad code — the
     * most confusing failure this design has (docs/oauth-sign-in.md §7.3).
     * CorsListener lets the cookie ride along.
     */
    #[Route('/exchange', name: 'api_auth_oauth_exchange', methods: ['POST'])]
    public function exchange(
        Request $httpRequest,
        #[MapRequestPayload] OAuthExchangeRequest $request,
    ): JsonResponse {
        // Read straight off the request; null when the browser sent none, which
        // the store treats as a failed binding, not a reason to skip the check.
        $browserToken = $httpRequest->cookies->get(self::FLOW_COOKIE);

        $token = $this->signIn->redeemLoginCode(
            $request->code,
            \is_string($browserToken) ? $browserToken : null,
        );

        // Code spent, session begun: the binding has nothing left to bind. Not
        // cleared when redeeming throws — the response is then the listener's,
        // and the cookie expires with the flow's ten minutes anyway.
        return $this->flowCookie->clearFrom(new JsonResponse(['token' => $token]));
    }

    /**
     * Step 2: the provider sends the browser back. GET (Google, query string) and
     * POST (Apple, form body — requesting a scope makes Apple require
     * `response_mode=form_post`). Every failure leaves as a redirect to the SPA
     * with an error code, never problem+json: the caller is a browser following a
     * redirect chain, and a JSON body would strand it showing raw JSON.
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

        // Read straight off the request; null when the browser sent none, which
        // the store treats as a failed binding, not a reason to skip the check.
        $cookie = $request->cookies->get(self::FLOW_COOKIE);
        $browserToken = \is_string($cookie) ? $cookie : null;
        $started = $this->stateStore->consume($state, $browserToken);

        // No valid state: not started by this server, already used, older than
        // ten minutes, or — the case state alone cannot catch — started by a
        // DIFFERENT BROWSER. All four are discarded without touching the provider.
        //
        // The fourth case is login CSRF, the reason the binding exists: an
        // attacker who obtains a state and code from their own account and gets a
        // victim to open this URL would otherwise sign the victim's browser in as
        // themselves, silently (the SPA exchanges with no user gesture). One code
        // for all four so a caller cannot probe for live states.
        //
        // The provider comparison stops a Google state replayed at Apple's
        // callback from spending a Google code against Apple's token endpoint, and
        // from letting the URL's chooser decide which provider is trusted.
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

        // consume() refuses a null token, so reaching here proves the cookie was
        // present and matched. Restated for the type checker.
        \assert(null !== $browserToken);

        // NOT cleared here, unlike every failure exit: the code minted below is
        // bound to this value and the exchange needs it one hop later; clearing
        // now would make every sign-in fail like a bad code. exchange() clears it.
        // A suspended or pending user reaches here too and leaves with a working
        // code — see OAuthSignIn::issueLoginCode() for why the status gate sits at
        // the exchange.
        return $this->oauthRedirect->success($this->signIn->issueLoginCode($identity, $browserToken));
    }

    /**
     * Step 1: send the browser to the provider. Declared LAST because
     * `/{provider}` is this controller's catch-all and would shadow every literal
     * route above it (see class docblock).
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
        // Only start() is capped: the callback's state is single-use and the
        // exchange's 32-byte code lives 30 seconds, so a limiter on either defends
        // something already closed, while start() is where a scripted loop could
        // fill the state pool for free.
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

        // The browser binding rides out with the redirect. Without it state would
        // prove only that THIS SERVER started some flow, letting anyone holding a
        // state and code spend them in another browser. See OAuthStateStore's
        // docblock for the full attack.
        \assert(null !== $state->browserToken);
        $response->headers->setCookie($this->flowCookie->issue($state->browserToken));

        return $response;
    }
}
