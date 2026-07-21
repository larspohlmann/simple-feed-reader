<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google's endpoints, scopes and issuers. Everything security-relevant — the
 * code exchange and the reading of the ID token — lives in the parent; this
 * class is deliberately nothing but configuration, which is what makes "a third
 * provider is one class and one env block" true.
 */
final class GoogleOAuthProvider extends AbstractOidcProvider
{
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * Constants, not configuration. The parent substitutes this host's TLS
     * certificate for the ID token's signature, so the host must not be
     * something a deployment — or a request — can move.
     */
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        HttpClientInterface $httpClient,
        ClockInterface $clock,
        #[Autowire('%env(APP_BACKEND_URL)%')] string $backendBaseUrl,
        #[Autowire('%env(GOOGLE_OAUTH_CLIENT_ID)%')] private readonly string $clientId,
        #[Autowire('%env(GOOGLE_OAUTH_CLIENT_SECRET)%')] private readonly string $clientSecret,
    ) {
        parent::__construct($httpClient, $clock, $backendBaseUrl);
    }

    public function getName(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            // `openid` for the ID token, `email` for the address. Nothing else:
            // the application shows an email and stores an email, so a wider
            // scope would only enlarge the consent screen and the blast radius.
            'scope' => 'openid email',
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
        // Google mints tokens with both spellings and has done for years.
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    protected function getClientId(): string
    {
        return $this->clientId;
    }

    protected function getClientSecret(): string
    {
        return $this->clientSecret;
    }
}
