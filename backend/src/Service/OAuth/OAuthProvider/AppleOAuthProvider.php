<?php

declare(strict_types=1);

namespace App\Service\OAuth\OAuthProvider;

use App\Service\OAuth\Factory\AppleClientSecretFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apple signs in with a per-exchange ES256 client secret and a cross-site `form_post` callback. The `id_token` in that
 * callback is never read: it did not come from the token endpoint, so nothing here may trust it.
 * docs/oauth-sign-in.md#the-id-token-trust-boundary
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

    /** `email` only: Apple returns an ID token without `openid`, and nothing reads `name`. */
    protected function getScope(): string
    {
        return 'email';
    }

    /**
     * Apple rejects a scoped request that does not declare `form_post`, and then posts the callback cross-site: hence
     * the callback route's POST and the flow cookie's `SameSite=None`.
     */
    protected function extraAuthorizationParameters(): array
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
