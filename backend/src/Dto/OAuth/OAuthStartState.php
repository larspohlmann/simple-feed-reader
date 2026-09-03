<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/**
 * The secrets belonging to one in-flight sign-in, from our 302 until the
 * provider calls back.
 *
 * Only `$state` and `$codeChallenge` ever leave the server toward the
 * PROVIDER; `$nonce` comes back inside the ID token; `$codeVerifier` goes to
 * the token endpoint over TLS.
 *
 * This branch deliberately does not verify the ID token's signature — see
 * AbstractOidcProvider, "Why the ID token's signature is not verified here".
 * So `$nonce` is worth checking not because the token is signed, but because
 * we fetched it ourselves, over validated TLS, from the provider's own token
 * endpoint, in direct response to our code. Calling it "signed" here would
 * wrongly imply the nonce check rests on cryptography it does not have.
 *
 * ## One DTO for both legs, and `$codeChallenge`
 *
 * `start()` and `consume()` share a shape, but on the callback leg nothing
 * reads `$codeChallenge` — the challenge already went to the provider; it is
 * the VERIFIER the token exchange needs back. `consume()` still recomputes
 * it from the stored verifier: the field is a pure function of
 * `$codeVerifier`, so recomputing costs one SHA-256 and keeps the invariant
 * total — `$codeChallenge` ALWAYS matches `$codeVerifier`, on every
 * instance. Leaving it empty or nullable on one leg would make the field
 * sometimes a lie, in a way PKCE failures make very hard to read.
 *
 * `$browserToken` is the one genuinely leg-specific field, nullable to say
 * so; a second such field should split this into a start DTO and a
 * resumption DTO rather than pile up nullables. It travels a third
 * direction — to the BROWSER, in a cookie, never to the provider — and is
 * what makes `state` mean "this browser started this flow", not merely
 * "this server started some flow" (see OAuthStateStore's docblock). Only
 * `start()` populates it; `consume()` has no reason to hand it back, since
 * supplying it back is how a flow gets redeemed in the first place.
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
