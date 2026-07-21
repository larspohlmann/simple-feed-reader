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
 *    is the account linker's problem, not this class's — the address arrives
 *    here as an ordinary verified email.
 *
 * Note what is NOT here: any handling of the `id_token` Apple puts in the
 * callback form body. That token did not come from the token endpoint, so the
 * signature-verification carve-out the parent relies on does not cover it, and
 * reading it would need JWKS verification this codebase does not implement. The
 * callback takes only the `code`; the ID token we trust is the one the token
 * endpoint hands back. See AbstractOidcProvider's class docblock.
 */
final class AppleOAuthProvider extends AbstractOidcProvider
{
    private const AUTHORIZATION_ENDPOINT = 'https://appleid.apple.com/auth/authorize';

    /**
     * Constants, not configuration — the parent substitutes this host's TLS
     * certificate for the ID token's signature, so the host must not be
     * something a deployment, or a request, can move.
     */
    private const TOKEN_ENDPOINT = 'https://appleid.apple.com/auth/token';
    private const ISSUER = 'https://appleid.apple.com';

    public function __construct(
        HttpClientInterface $httpClient,
        ClockInterface $clock,
        #[Autowire('%env(APP_BACKEND_URL)%')] string $backendBaseUrl,
        private readonly AppleClientSecretFactory $clientSecretFactory,
        #[Autowire('%env(APPLE_OAUTH_CLIENT_ID)%')] private readonly string $servicesId,
    ) {
        parent::__construct($httpClient, $clock, $backendBaseUrl);
    }

    public function getName(): string
    {
        return 'apple';
    }

    /**
     * Delegated in full: Apple's credentials are the secret factory's four
     * values, and there is no fifth thing this class could separately be
     * missing. Keeping the answer in one place is what stops a deployment from
     * being "configured" here and unable to sign there.
     */
    public function isConfigured(): bool
    {
        return $this->clientSecretFactory->isConfigured();
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->servicesId,
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            // `email` only. Apple's documented scopes are `name` and `email`,
            // and it returns an ID token from the token endpoint regardless, so
            // unlike Google there is no `openid` to add. The application shows
            // an email and stores an email; `name` would widen the consent
            // screen for data nothing reads.
            'scope' => 'email',
            // Requesting any scope at all makes Apple deliver the callback as a
            // POST with a form body instead of a GET with a query string, and
            // Apple rejects the authorization request outright if the mode is
            // not declared. This is why OAuthController's callback route
            // accepts both methods, and why flow state cannot live in a
            // SameSite=Lax session cookie: a cross-site POST does not carry it.
            'response_mode' => 'form_post',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    protected function getTokenEndpoint(): string
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
