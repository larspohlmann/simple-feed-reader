<?php

declare(strict_types=1);

namespace App\Service\OAuth\OAuthProvider;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Model\OAuthIdentityModel;

/**
 * What the application needs from an identity provider, and nothing more: it authenticates people and never calls
 * the provider again, so access and refresh tokens are deliberately not exposed.
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

    public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string;

    /**
     * @throws OAuthFailedException on any failure; the caller must not be able to tell the failures apart
     */
    public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentityModel;
}
