<?php

declare(strict_types=1);

namespace App\Service\Identity\OAuth\Oidc\Model;

use App\Service\Identity\OAuth\Oidc\Pass\IdTokenVerifier;
use App\Service\Identity\OAuth\Oidc\Pass\TokenEndpoint;

/**
 * An ID token fetched by {@see TokenEndpoint::fetch()} over validated TLS, its only constructor (OidcBoundaryTest).
 * That origin is why {@see IdTokenVerifier} may skip the signature: never wrap a token from another channel.
 * docs/oauth-sign-in.md#the-id-token-trust-boundary
 */
final readonly class IdTokenModel
{
    public function __construct(public string $jwt)
    {
    }
}
