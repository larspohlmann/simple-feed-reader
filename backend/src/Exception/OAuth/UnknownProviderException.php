<?php

declare(strict_types=1);

namespace App\Exception\OAuth;

use Symfony\Component\HttpFoundation\Response;

/**
 * The URL named a provider this deployment does not offer — either a typo, a
 * probe, or a provider whose credentials are not configured. All three are
 * "no such thing here", so all three are a 404.
 *
 * Note the third case is the reason this is not a routing concern: an
 * unconfigured provider must be indistinguishable from a nonexistent one, or
 * the 404-vs-503 difference would tell a prober exactly which providers this
 * deployment has keys for.
 */
final class UnknownProviderException extends OAuthException
{
    public function __construct()
    {
        parent::__construct(
            'unknown_provider',
            Response::HTTP_NOT_FOUND,
            'Unknown sign-in provider',
            'That sign-in provider is not available.',
        );
    }
}
