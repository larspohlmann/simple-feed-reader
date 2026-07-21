<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;

/**
 * Everything the application needs from an identity provider, in two calls.
 *
 * Deliberately narrow. Nothing here exposes access tokens, refresh tokens or
 * profile scopes: this application authenticates people and then never speaks
 * to the provider again, so holding a token we do not use would be a liability
 * with no upside. Adding a third provider is one implementation of this
 * interface plus an env block — no migration, per the design spec.
 */
interface OAuthProviderInterface
{
    /**
     * The short name used in URLs and stored in `user_identity.provider`.
     */
    public function getName(): string;

    /**
     * False when the deployment has not configured credentials for this
     * provider. An unconfigured provider is invisible: its routes 404 rather
     * than redirecting to a broken consent screen.
     */
    public function isConfigured(): bool;

    /**
     * The provider URL to send the browser to.
     *
     * @param string $state         opaque single-use key into OAuthStateStore
     * @param string $nonce         replayed in the ID token, checked on return
     * @param string $codeChallenge S256 challenge derived from the PKCE verifier
     */
    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string;

    /**
     * Trade the authorization code for a verified identity.
     *
     * @param string $code         the provider's one-time authorization code
     * @param string $codeVerifier the PKCE verifier matching the challenge
     * @param string $nonce        the nonce that must appear in the ID token
     *
     * @throws OAuthFailedException on any provider error, malformed token, or
     *                              failed nonce check — the caller must not be
     *                              able to tell those apart
     */
    public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity;
}
