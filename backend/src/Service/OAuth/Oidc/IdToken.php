<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

/**
 * An ID token that came back from an OIDC token endpoint, over a TLS connection
 * this application validated, with no redirect in between.
 *
 * ## This type is a precondition, not a wrapper
 *
 * Nothing here parses, checks or even looks at the string. The class exists so
 * that "where did this token come from" is expressed in the type system instead
 * of in a comment somebody has to remember to read.
 *
 * {@see IdTokenVerifier} does NOT verify the token's signature, and is only
 * entitled to skip that because of where the token came from — see the verifier's
 * docblock for the full argument. It therefore accepts an IdToken and never a
 * string, so a raw JWT from anywhere else cannot be passed to it by mistake.
 *
 * {@see TokenEndpoint::fetch()} is the ONLY place in this application that
 * constructs one, which is what makes the type mean what it says.
 * OidcBoundaryTest fails the build if a second construction site appears, so
 * this paragraph cannot quietly stop being true.
 *
 * If a future task needs to read an ID token that did NOT come from the token
 * endpoint — Apple's `form_post` callback carries one, for instance — that task
 * must verify the signature against the provider's JWKS in its own code.
 * Constructing an IdToken around such a token to reuse the verifier would be
 * lying to the type, and it would silently skip the signature check that is the
 * only thing standing behind a token from that channel.
 */
final readonly class IdToken
{
    public function __construct(public string $jwt)
    {
    }
}
