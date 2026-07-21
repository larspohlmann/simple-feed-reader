<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/**
 * The secrets belonging to one in-flight sign-in, from our 302 until the
 * provider calls back.
 *
 * Only `$state` and `$codeChallenge` ever leave the server towards the
 * PROVIDER; `$nonce` comes back inside the signed ID token and `$codeVerifier`
 * is sent to the token endpoint over TLS.
 *
 * `$browserToken` is the odd one out and travels in a third direction: it goes
 * to the BROWSER, in a cookie, and never to the provider at all. It is what
 * makes `state` mean "this browser started this flow" rather than merely "this
 * server started some flow" — see OAuthStateStore's docblock. It is populated
 * only by start(); consume() has no reason to hand it back, because the caller
 * supplying it is how a flow is redeemed in the first place.
 */
final readonly class OAuthStartState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
        public ?string $browserToken = null,
    ) {
    }
}
