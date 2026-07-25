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
 * The half of an OpenID Connect provider that is identical for Google and
 * Apple, expressed as the two legs of the protocol:
 *
 * - **Outbound.** getAuthorizationUrl() assembles the consent request from the
 *   four things providers actually differ by — endpoint, client id, scope, and
 *   whatever extraAuthorizationParams() adds.
 * - **Inbound.** exchangeCode() POSTs the authorization code to the token
 *   endpoint and reads the identity out of the ID token that comes back.
 *
 * A subclass supplies configuration and nothing else. Both public methods are
 * `final`, so a provider that means only to add an authorization parameter
 * cannot quietly drop a standard one, and cannot alter how the token is
 * obtained or checked.
 *
 * ## Where the security argument lives
 *
 * The inbound leg does NOT verify the ID token's signature. That is permitted
 * by OpenID Connect Core §3.1.3.7 item 6, but only for a token fetched straight
 * off the token endpoint over validated TLS — a genuinely narrow carve-out with
 * three preconditions, each enforced rather than assumed. This class no longer
 * makes that argument itself; it composes the two collaborators that do:
 *
 * - {@see TokenEndpoint} performs the fetch and enforces the three
 *   preconditions (an `https` endpoint we hardcode, pinned TLS, no redirects).
 *   It is the only place that can mint an {@see \App\Service\OAuth\Oidc\IdToken}.
 * - {@see IdTokenVerifier} checks everything TLS says nothing about — `iss`,
 *   `aud`, `azp`, `exp`, `nonce`, `sub` — and accepts only an IdToken, so it
 *   cannot be handed a token from another channel by mistake.
 *
 * Read those two docblocks before changing anything about how a token is
 * fetched or trusted. The boundary between them is load-bearing and is pinned
 * by OidcBoundaryTest.
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
     * Kept abstract rather than defaulted, because there is no scope string
     * that is right for an unknown provider and a wrong default here would be
     * a silently over-broad consent screen. Google needs `openid email`; Apple
     * needs `email` and mints an ID token regardless.
     */
    abstract protected function getScope(): string;

    /**
     * Anything this provider needs in the authorization request that OIDC does
     * not define — Apple's `response_mode=form_post` is the only instance so
     * far, and most providers will override nothing.
     *
     * Merged AFTER the standard parameters and therefore able to overwrite
     * them. That is deliberate: a provider that genuinely needs a different
     * `response_type` should be able to say so in one line rather than fork the
     * whole method. It also means a careless override can weaken the request —
     * dropping `code_challenge_method` back to `plain`, say — so treat an
     * override that touches a standard key as needing the same justification
     * the parameter itself had.
     *
     * @return array<string, string>
     */
    protected function extraAuthorizationParams(): array
    {
        return [];
    }

    /**
     * `final`, so the standard parameters cannot be quietly dropped by a
     * subclass that meant only to add one. PKCE in particular is not optional
     * here — `code_challenge_method` is `S256` and the plain method is not
     * offered anywhere in this codebase (see OAuthStateStore::challengeFor).
     *
     * PHP_QUERY_RFC3986 rather than the default RFC1738, so a space encodes as
     * `%20` and not `+`. Both are accepted in a query string by every provider
     * involved, but `+` is the one that goes wrong when a value is later read
     * out of a path or a header, and the scope strings here contain spaces.
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
     * The redirect URI, built from configuration rather than from the incoming
     * request.
     *
     * This must never be derived from the Host header. The value is echoed to
     * the token endpoint and must match what is registered with the provider
     * byte for byte; deriving it from a header an attacker can set would, on a
     * server that does not pin its host, turn into a redirect of the
     * authorization code to somewhere else.
     */
    final public function getRedirectUri(): string
    {
        return rtrim($this->backendBaseUrl, '/') . '/api/auth/oauth/' . $this->getName() . '/callback';
    }

    final public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity
    {
        if ('' === $nonce) {
            // Not a provider failure but a caller bug, and a dangerous one: the
            // verifier's nonce check is an equality test, and '' === '' is true,
            // so an empty expectation would silently accept a token carrying an
            // empty nonce.
            //
            // Refused HERE, before the token endpoint is called, so a broken
            // caller does not spend a single-use authorization code on an
            // exchange that cannot succeed. IdTokenVerifier refuses it a second
            // time, as the backstop that holds no matter who calls it.
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
