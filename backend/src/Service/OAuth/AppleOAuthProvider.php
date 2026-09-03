<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apple's endpoints and the three ways it differs from every other OIDC
 * provider this codebase talks to.
 *
 * 1. **There is no static client secret.** Apple issues a private key once and
 *    expects a short ES256 JWT signed with it in the `client_secret` field.
 *    That lives in AppleClientSecretFactory; getClientSecret() mints a fresh
 *    one per exchange.
 * 2. **The callback is a cross-site POST.** Requesting any scope switches Apple
 *    to `response_mode=form_post`, which is why the callback route accepts POST
 *    and why the flow state cannot live in a SameSite=Lax cookie.
 * 3. **The email may be a per-app relay** (`…@privaterelay.appleid.com`). That
 *    is the account linker's problem, not this class's — it arrives here as an
 *    ordinary verified email.
 *
 * Note what is NOT here: handling of the `id_token` Apple puts in the callback
 * form body. It did not come from the token endpoint, so the parent's
 * signature-verification carve-out does not cover it, and reading it would
 * need JWKS verification this codebase does not implement. The callback takes
 * only the `code`; see AbstractOidcProvider's class docblock for the ID token
 * we actually trust.
 */
final readonly class AppleOAuthProvider extends AbstractOidcProvider
{
    private const string AUTHORIZATION_ENDPOINT = 'https://appleid.apple.com/auth/authorize';

    /**
     * Constants, not configuration — the parent substitutes this host's TLS
     * certificate for the ID token's signature, so the host must not be
     * something a deployment, or a request, can move.
     */
    private const string TOKEN_ENDPOINT = 'https://appleid.apple.com/auth/token';
    private const string ISSUER = 'https://appleid.apple.com';

    public function __construct(
        HttpClientInterface $httpClient,
        ClockInterface $clock,
        #[Autowire('%env(APP_BACKEND_URL)%')] string $backendBaseUrl,
        private AppleClientSecretFactory $clientSecretFactory,
        #[Autowire('%env(APPLE_OAUTH_CLIENT_ID)%')] private string $servicesId,
    ) {
        parent::__construct($httpClient, $clock, $backendBaseUrl);
    }

    public function getName(): string
    {
        return 'apple';
    }

    /**
     * Delegated in full: Apple's credentials are the secret factory's four
     * values, with no fifth thing this class could be missing. One answer in one
     * place stops a deployment being "configured" here and unable to sign there.
     */
    public function isConfigured(): bool
    {
        return $this->clientSecretFactory->isConfigured();
    }

    protected function getAuthorizationEndpoint(): string
    {
        return self::AUTHORIZATION_ENDPOINT;
    }

    /**
     * `email` only. Apple's documented scopes are `name` and `email`, and it
     * returns an ID token regardless, so unlike Google there is no `openid` to
     * add. The app shows and stores an email; `name` would widen the consent
     * screen for data nothing reads.
     */
    protected function getScope(): string
    {
        return 'email';
    }

    /**
     * The one parameter Apple needs that OIDC does not define, and the reason
     * this hook exists on the parent.
     *
     * Requesting any scope makes Apple deliver the callback as a POST with a
     * form body instead of a GET, and Apple rejects the request if the mode
     * isn't declared. That is why OAuthController's callback route accepts
     * both methods, and why the flow-binding cookie cannot be `SameSite=Lax`
     * — a cross-site POST does not carry one.
     */
    protected function extraAuthorizationParams(): array
    {
        return ['response_mode' => 'form_post'];
    }

    protected function getTokenEndpointUrl(): string
    {
        return self::TOKEN_ENDPOINT;
    }

    protected function getIssuers(): array
    {
        // Unlike Google, Apple only ever uses the one spelling.
        return [self::ISSUER];
    }

    protected function getClientId(): string
    {
        return $this->servicesId;
    }

    /**
     * Signed fresh for every exchange — see AppleClientSecretFactory for why
     * this is not cached, and for what happens when the key is unusable.
     */
    protected function getClientSecret(): string
    {
        return $this->clientSecretFactory->create();
    }
}
