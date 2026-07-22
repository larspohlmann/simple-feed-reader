<?php

declare(strict_types=1);

namespace App\Exception\OAuth;

use Symfony\Component\HttpFoundation\Response;

/**
 * The conversation with the provider did not produce a usable identity.
 *
 * One exception for every failure mode on purpose — network error, HTTP 4xx
 * from the token endpoint, unparseable ID token, audience mismatch, nonce
 * mismatch. The user can do nothing differently for any of them, and a caller
 * who could tell them apart would be holding a probe into our configuration.
 * The specific cause goes to the log via $logDetail and $previous, never to
 * the response: $detail below is the same sentence for all of them.
 */
final class OAuthFailedException extends OAuthException
{
    /**
     * Promoted and readonly so the "kept out of the response" guarantee is
     * structural rather than a convention. Nothing can rewrite it after
     * construction, and ApiExceptionListener builds the problem document from
     * ApiException's own properties only — it has no way to reach this one.
     *
     * @param string $logDetail short machine-ish description of what failed,
     *                          for the log context; never rendered to a client
     */
    public function __construct(
        public readonly string $logDetail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            'oauth_failed',
            Response::HTTP_BAD_GATEWAY,
            'Sign-in failed',
            'Signing in with that provider did not work. Please try again.',
            previous: $previous,
        );
    }
}
