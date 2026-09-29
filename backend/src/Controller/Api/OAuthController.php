<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\OAuth\OAuthExchangeRequest;
use App\Http\OAuth\CallbackParameters;
use App\Http\OAuth\Factory\OAuthRedirectFactory;
use App\Http\OAuth\FlowCookie;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\Model\OAuthCallbackAttemptModel;
use App\Service\OAuth\OAuthCallback;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Random\RandomException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The three-legged sign-in's HTTP: redirect out, callback in, code for token. Route order is load-bearing: the
 * literal routes come before `/{provider}`, which would otherwise match `providers` and `exchange`.
 */
#[Route('/api/auth/oauth')]
final readonly class OAuthController
{
    /**
     * Browser-binding cookie name, re-exported from the collaborator that owns
     * its lifecycle. Public because OAuthFlowTest asserts against it; see
     * {@see FlowCookie::NAME} for why the `__Host-` prefix and attributes matter.
     */
    public const string FLOW_COOKIE = FlowCookie::NAME;

    /** Bounds the `{provider}` segment; it does not settle the `providers` collision, route order does. */
    private const string PROVIDER_PATTERN = '[a-z][a-z0-9_-]{1,31}';

    public function __construct(
        private OAuthProviderRegistry $providers,
        private OAuthStateStore $stateStore,
        private OAuthSignIn $signIn,
        private OAuthCallback $callback,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $oauthStartLimiter,
        private FlowCookie $flowCookie,
        private OAuthRedirectFactory $oauthRedirectFactory,
    ) {
    }

    /** Unauthenticated: it reveals only the sign-in buttons the login page shows anyway. */
    #[Route('/providers', name: 'api_auth_oauth_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return new JsonResponse(['providers' => $this->providers->getConfiguredNames()]);
    }

    /**
     * Step 3: the SPA trades the code for the JWT in a POST body. It must send credentials: without the flow cookie
     * the answer is the same 400 as a bad code.
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
     * Step 2: the provider sends the browser back, by GET (Google) or by POST (Apple's form_post). Every failure
     * is a redirect to the SPA with an error code, never problem+json: a browser mid-redirect would show raw JSON.
     *
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    #[Route(
        '/{provider}/callback',
        name: 'api_auth_oauth_callback',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function callback(string $provider, Request $request): RedirectResponse
    {
        $cookie = $request->cookies->get(self::FLOW_COOKIE);
        $attempt = new OAuthCallbackAttemptModel(
            provider: $provider,
            declined: null !== CallbackParameters::read($request, 'error'),
            state: CallbackParameters::read($request, 'state'),
            code: CallbackParameters::read($request, 'code'),
            browserToken: \is_string($cookie) ? $cookie : null,
        );

        try {
            // The success redirect leaves the flow cookie set: the exchange one hop later needs the binding.
            return $this->oauthRedirectFactory->success($this->callback->complete($attempt));
        } catch (OAuthCallbackRefusedException $refusal) {
            return $this->oauthRedirectFactory->failure($refusal->failure->value);
        }
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
        // Only start() is capped: states and login codes are single-use and short-lived, while a scripted start loop
        // could fill the state pool for free.
        $this->rateLimitGuard->enforceForClient($this->oauthStartLimiter, $request->getClientIp());

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

        // The browser binding rides out with the redirect; without it `state` proves only that this server started
        // some flow.
        \assert(null !== $state->browserToken);
        $response->headers->setCookie($this->flowCookie->issue($state->browserToken));

        return $response;
    }
}
