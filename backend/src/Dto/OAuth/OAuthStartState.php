<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/**
 * The secrets belonging to one in-flight sign-in, from our 302 until the
 * provider calls back.
 *
 * Only `$state` and `$codeChallenge` ever leave the server towards the
 * PROVIDER; `$nonce` comes back inside the ID token and `$codeVerifier` is sent
 * to the token endpoint over TLS.
 *
 * The ID token carries a signature, and this branch deliberately does NOT
 * verify it — see AbstractOidcProvider, "Why the ID token's signature is not
 * verified here". So what makes `$nonce` worth checking is not a signature: it
 * is that the token was fetched by us, over validated TLS, from the provider's
 * own token endpoint in direct response to our code. Calling the token "signed"
 * here would invite the assumption that the nonce check rests on cryptography
 * it does not rest on.
 *
 * ## One DTO for both legs, and what that means for `$codeChallenge`
 *
 * `start()` and `consume()` return the same shape, and on the callback leg
 * nothing reads `$codeChallenge` — the challenge went to the provider ten
 * minutes ago and it is the VERIFIER the token exchange needs back. So
 * `consume()` recomputing it from the stored verifier looks like dead work.
 *
 * It is kept, deliberately, because the alternative is worse. The field is a
 * pure function of `$codeVerifier`, so recomputing it costs one SHA-256 and
 * keeps the type's invariant total: `$codeChallenge` ALWAYS matches
 * `$codeVerifier`, on every instance, whoever built it. Leaving it empty or
 * making it nullable on one leg would introduce a field that is sometimes a
 * lie, and a future caller that reasonably trusted it would be wrong in a way
 * PKCE failures make very hard to read.
 *
 * `$browserToken` is the one field that genuinely is leg-specific, and it is
 * nullable to say so. If a second field ever earns that treatment, split this
 * into a start DTO and a resumption DTO rather than accumulating nullables.
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
