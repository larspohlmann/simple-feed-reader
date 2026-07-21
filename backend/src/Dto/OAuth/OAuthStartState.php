<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/**
 * The secrets belonging to one in-flight sign-in, from our 302 until the
 * provider calls back.
 *
 * Only `$state` and `$codeChallenge` ever leave the server; `$nonce` comes
 * back inside the signed ID token and `$codeVerifier` is sent to the token
 * endpoint over TLS.
 */
final readonly class OAuthStartState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
    ) {
    }
}
