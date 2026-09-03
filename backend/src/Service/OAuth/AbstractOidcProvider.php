<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;
use App\Service\OAuth\Oidc\IdTokenVerifier;
use App\Service\OAuth\Oidc\TokenEndpoint;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The half of an OpenID Connect provider identical for Google and Apple —
 * two legs of the protocol:
 *
 * - **Outbound.** getAuthorizationUrl() assembles the consent request from the
 *   four things providers differ by — endpoint, client id, scope, and whatever
 *   extraAuthorizationParams() adds.
 * - **Inbound.** exchangeCode() POSTs the authorization code to the token
 *   endpoint and reads the identity from the ID token that comes back.
 *
 * A subclass supplies configuration only. Both public methods are `final`, so a
 * provider cannot drop a standard parameter or alter how the token is obtained
 * or checked.
 *
 * ## Where the security argument lives
 *
 * The inbound leg does NOT verify the ID token's signature — permitted by
 * OpenID Connect Core §3.1.3.7 item 6, but only for a token fetched straight off
 * the token endpoint over validated TLS, a narrow carve-out with three
 * preconditions, each enforced by one of two collaborators:
 *
 * - {@see TokenEndpoint} enforces the preconditions (an `https` endpoint we
 *   hardcode, pinned TLS, no redirects) and is the only place that can mint an
 *   {@see \App\Service\OAuth\Oidc\IdToken}.
 * - {@see IdTokenVerifier} checks everything TLS says nothing about — `iss`,
 *   `aud`, `azp`, `exp`, `nonce`, `sub` — and accepts only an IdToken, so it
 *   cannot be handed a token from another channel.
 *
 * Read both docblocks before changing how a token is fetched or trusted. The
 * boundary is load-bearing and pinned by OidcBoundaryTest.
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

    /**
     * What this application asks the user to consent to.
     *
     * Abstract rather than defaulted: no scope string is right for an unknown
     * provider, and a wrong default would be a silently over-broad consent
     * screen. Google needs `openid email`; Apple needs `email` and mints an ID
     * token regardless.
     */
    abstract protected function getScope(): string;

    /**
     * Anything this provider needs in the authorization request that OIDC does
     * not define — Apple's `response_mode=form_post` is the only instance so far.
     *
     * Merged AFTER the standard parameters, so it can overwrite them: a provider
     * needing a different `response_type` says so in one line rather than fork
     * the method. That also means a careless override can weaken the request —
     * e.g. dropping `code_challenge_method` to `plain` — so overriding a
     * standard key needs the same justification the parameter had.
     *
     * @return array<string, string>
     */
    protected function extraAuthorizationParams(): array
    {
        return [];
    }

    /**
     * `final`, so a subclass cannot drop a standard parameter. PKCE is not
     * optional here — `code_challenge_method` is `S256`; plain is offered
     * nowhere in this codebase (see OAuthStateStore::challengeFor).
     *
     * PHP_QUERY_RFC3986 rather than the default RFC1738, so a space encodes as
     * `%20` not `+` — `+` goes wrong once a value is read back out of a path or
     * header, and the scope strings here contain spaces.
     */
    final public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $params = [
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
            array_merge($params, $this->extraAuthorizationParams()),
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
    }

    /**
     * The redirect URI, built from configuration, never from the Host header.
     * It is echoed to the token endpoint and must match what is registered
     * with the provider byte for byte; deriving it from an attacker-settable
     * header would, on a server that does not pin its host, redirect the
     * authorization code elsewhere.
     */
    final public function getRedirectUri(): string
    {
        return rtrim($this->backendBaseUrl, '/') . '/api/auth/oauth/' . $this->getName() . '/callback';
    }

    final public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity
    {
        if ('' === $nonce) {
            // Dangerous caller bug: '' === '' is true, so an empty expectation
            // would silently accept a token with an empty nonce. Refused HERE,
            // before the token endpoint runs, so a broken caller doesn't burn a
            // single-use code on a doomed exchange. IdTokenVerifier refuses it
            // again as the backstop.
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
