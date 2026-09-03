<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

/**
 * An ID token that came back from an OIDC token endpoint, over a TLS connection
 * this application validated, with no redirect in between.
 *
 * ## This type is a precondition, not a wrapper
 *
 * Nothing here parses, checks or looks at the string. The class exists so
 * "where did this token come from" lives in the type system, not a comment
 * somebody has to remember to read.
 *
 * {@see IdTokenVerifier} does NOT verify the token's signature, and is only
 * entitled to skip that because of where the token came from — see the
 * verifier's docblock. It accepts an IdToken and never a string, so a raw JWT
 * from anywhere else cannot be passed to it by mistake.
 *
 * {@see TokenEndpoint::fetch()} is the ONLY place that constructs one, which
 * is what makes the type mean what it says. OidcBoundaryTest fails the build
 * if a second construction site appears.
 *
 * A future ID token that did NOT come from the token endpoint — Apple's
 * `form_post` callback carries one — must be verified against the provider's
 * JWKS in its own code. Wrapping it in an IdToken to reuse this verifier
 * would lie to the type and silently skip the only check standing behind
 * that channel.
 */
final readonly class IdToken
{
    public function __construct(public string $jwt)
    {
    }
}
