<?php

declare(strict_types=1);

namespace App\Service\OAuth\OAuthProvider;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Model\OAuthIdentityModel;
use App\Service\OAuth\Oidc\Pass\IdTokenVerifier;
use App\Service\OAuth\Oidc\Pass\TokenEndpoint;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The OpenID Connect sign-in shared by Google and Apple; a subclass supplies configuration only, and both legs are
 * `final`. How a token is fetched and trusted is a security boundary pinned by OidcBoundaryTest:
 * docs/oauth-sign-in.md#the-id-token-trust-boundary
 */
abstract readonly class AbstractOidcProvider implements OAuthProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private string $backendBaseUrl,
    ) {
    }

    /**
     * MUST be an `https://` URL and MUST NOT be derived from anything in the
     * request. See {@see TokenEndpoint} for why that is load-bearing: a token
     * must not be able to nominate the authority that vouches for it.
     */
    abstract protected function getTokenEndpointUrl(): string;

    /**
     * Accepted `iss` values. A list because Google mints tokens with both
     * `https://accounts.google.com` and the bare `accounts.google.com`.
     *
     * @return list<string>
     */
    abstract protected function getIssuers(): array;

    abstract protected function getClientId(): string;

    abstract protected function getClientSecret(): string;

    /**
     * Where the browser is sent to consent. A constant in each subclass, for
     * the same reason getTokenEndpointUrl() is.
     */
    abstract protected function getAuthorizationEndpoint(): string;

    /** Abstract, not defaulted: a wrong default would be a silently over-broad consent screen. */
    abstract protected function getScope(): string;

    /**
     * Parameters OIDC does not define (Apple's `response_mode`). Merged last, so an override can replace a standard
     * key: weakening one, say `code_challenge_method`, needs the justification the parameter had.
     *
     * @return array<string, string>
     */
    protected function extraAuthorizationParameters(): array
    {
        return [];
    }

    /** PKCE is always S256; RFC 3986 encoding makes a space in the scope `%20`, never `+`. */
    final public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $queryParameters = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => $this->getScope(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->getAuthorizationEndpoint() . '?' . http_build_query(
            array_merge($queryParameters, $this->extraAuthorizationParameters()),
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
    }

    /**
     * Built from configuration, never the Host header, which on a server that does not pin its host would let a
     * request redirect the authorization code elsewhere. Must match the provider's registration byte for byte.
     */
    final public function getRedirectUri(): string
    {
        return rtrim($this->backendBaseUrl, '/') . '/api/auth/oauth/' . $this->getName() . '/callback';
    }

    final public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentityModel
    {
        if ('' === $nonce) {
            // An empty expectation would accept a token with an empty nonce. Refused before the token call so a broken
            // caller does not burn a single-use code; IdTokenVerifier refuses it again.
            throw new OAuthFailedException('no nonce to check the id_token against');
        }

        return $this->verifier()->verify(
            $this->tokenEndpoint()->fetch($code, $codeVerifier),
            $nonce,
        );
    }

    /**
     * Both collaborators are built per exchange rather than injected, because
     * each is configured from this provider's own abstract getters — which is
     * also what keeps a subclass unable to reach past them.
     */
    private function tokenEndpoint(): TokenEndpoint
    {
        return new TokenEndpoint(
            $this->httpClient,
            $this->getTokenEndpointUrl(),
            $this->getClientId(),
            $this->getClientSecret(),
            $this->getRedirectUri(),
        );
    }

    private function verifier(): IdTokenVerifier
    {
        return new IdTokenVerifier(
            $this->clock,
            $this->getName(),
            $this->getClientId(),
            $this->getIssuers(),
        );
    }
}
