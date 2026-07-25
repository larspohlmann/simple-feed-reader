<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;
use Psr\Clock\ClockInterface;

/**
 * Turns an ID token this application fetched itself into a verified identity,
 * or refuses it.
 *
 * ## Why the ID token's signature is not verified here
 *
 * This looks alarming and is not. OpenID Connect Core §3.1.3.7 item 6:
 *
 * > If the ID Token is received via direct communication between the Client
 * > and the Token Endpoint (which it is in this flow), the TLS server
 * > validation MAY be used to validate the issuer in place of checking the
 * > token signature. The Client MUST validate the signature of all other ID
 * > Tokens according to JWS using the algorithm specified in the JWT `alg`
 * > Header Parameter.
 *
 * Read the carve-out narrowly, because it is narrow. It buys exactly one
 * thing — the assurance that the token came from the issuer — and it buys it
 * only for a token pulled straight off the token endpoint over TLS we
 * validated. It is not a general permission to skip signatures.
 *
 * ## What keeps that precondition true
 *
 * verify() takes an {@see IdToken} and never a string. That type means "fetched
 * from a token endpoint, over validated TLS, with no redirect in between", and
 * {@see TokenEndpoint::fetch()} — which enforces all three — is the only place
 * in this application that constructs one. So a raw JWT from any other channel
 * cannot be handed to this class by mistake: there is no overload that accepts
 * one.
 *
 * That boundary used to be expressed by keeping these methods `private` on the
 * provider. It is now expressed by the parameter type, which is checked by the
 * type system at every call site rather than by whoever is reading the file —
 * and by OidcBoundaryTest, which fails the build if a second construction site
 * for IdToken appears. What the boundary CANNOT do, in either form, is stop
 * somebody who deliberately writes `new IdToken($jwt)` around a token from
 * somewhere else. If a future task needs to read an ID token that did not come
 * from the token endpoint — Apple's `form_post` callback carries one, for
 * instance — that task must verify the signature against the provider's JWKS in
 * its own code. Wrapping it in an IdToken to reuse this class would skip the
 * only check standing behind a token from that channel.
 *
 * ## What is checked, because TLS says nothing about it
 *
 * `aud` and `azp` (the token was minted for us and issued to us), `iss` (it came
 * from the expected issuer), `exp` (it is current), `nonce` (it belongs to the
 * flow this browser started), and `sub` (it names an identity we can store
 * without collisions). Each is one small guard below, in that order, and every
 * one of them raises the same OAuthFailedException — the caller must not be able
 * to tell "no such client" from "expired token", so only $logDetail differs.
 */
final readonly class IdTokenVerifier
{
    /**
     * A token whose `exp` has just passed is not evidence of an attack — it is
     * evidence of clock drift between us and the provider. A small tolerance
     * avoids failing honest logins; anything larger would meaningfully extend
     * the life of a captured token.
     */
    private const int CLOCK_SKEW_SECONDS = 60;

    /**
     * @param string       $provider the name recorded on the resulting identity
     * @param list<string> $issuers  accepted `iss` values. A list because Google
     *                               mints tokens with both `https://accounts.google.com`
     *                               and the bare `accounts.google.com`.
     */
    public function __construct(
        private ClockInterface $clock,
        private string $provider,
        private string $clientId,
        private array $issuers,
    ) {
    }

    public function verify(IdToken $token, string $expectedNonce): OAuthIdentity
    {
        if ('' === $expectedNonce) {
            // The nonce check below is an equality test, and '' === '' is true,
            // so an empty expectation would silently accept a token carrying an
            // empty nonce. Refused here so that comparison is never asked to
            // defend a value that cannot defend itself.
            //
            // AbstractOidcProvider::exchangeCode() refuses the same thing before
            // it calls the token endpoint, so an honest caller never spends an
            // authorization code on a doomed exchange. This one is the backstop
            // that holds regardless of who constructed this verifier.
            throw new OAuthFailedException('no nonce to check the id_token against');
        }

        $claims = IdTokenClaims::decode($token);

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertAuthorizedParty($claims);
        $this->assertNotExpired($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $this->identityFrom($claims);
    }

    private function assertIssuer(IdTokenClaims $claims): void
    {
        $issuer = $claims->string('iss');

        if (null === $issuer || !\in_array($issuer, $this->issuers, true)) {
            throw new OAuthFailedException('id_token issuer mismatch');
        }
    }

    /**
     * hash_equals is not here because a client id is secret — it is not. It is
     * here so that every identity comparison in this class reads the same way,
     * and so nobody later has to work out which of them were the sensitive ones.
     * The cost is a function call.
     */
    private function assertAudience(IdTokenClaims $claims): void
    {
        $mintedForUs = array_any(
            $claims->stringList('aud'),
            fn (string $audience): bool => hash_equals($this->clientId, $audience),
        );

        if (!$mintedForUs) {
            throw new OAuthFailedException('id_token audience mismatch');
        }
    }

    /**
     * OIDC Core §3.1.3.7 item 5: when `azp` is present it names the client the
     * token was issued to, which may differ from the audience. Google omits it
     * in the single-client case and Apple never sends it, so in practice this
     * only ever fires on a token that took a detour.
     *
     * Item 4's companion SHOULD — require `azp` to be PRESENT whenever `aud` has
     * several values — is deliberately not implemented. It exists to tell you
     * who presented a token when several parties could have, and here only one
     * party ever can: we fetch the token ourselves, from an endpoint we
     * hardcode, authenticated with our own client secret. Enforcing it would
     * reject nothing an attacker can send and would break the day a provider
     * starts adding a second audience.
     */
    private function assertAuthorizedParty(IdTokenClaims $claims): void
    {
        if (null === $claims->claim('azp')) {
            return;
        }

        $authorizedParty = $claims->string('azp');

        if (null === $authorizedParty || !hash_equals($this->clientId, $authorizedParty)) {
            throw new OAuthFailedException('id_token authorized party mismatch');
        }
    }

    private function assertNotExpired(IdTokenClaims $claims): void
    {
        $expiry = $claims->int('exp');

        if (null === $expiry || $expiry + self::CLOCK_SKEW_SECONDS < $this->clock->now()->getTimestamp()) {
            throw new OAuthFailedException('id_token expired or has no exp');
        }
    }

    /**
     * The one check that ties this token to the browser that started the flow.
     * Without it, a token obtained anywhere could be replayed into somebody
     * else's callback. hash_equals rather than === so the comparison cannot be
     * walked character by character.
     */
    private function assertNonce(IdTokenClaims $claims, string $expectedNonce): void
    {
        $nonce = $claims->string('nonce');

        if (null === $nonce || !hash_equals($expectedNonce, $nonce)) {
            throw new OAuthFailedException('id_token nonce mismatch');
        }
    }

    private function identityFrom(IdTokenClaims $claims): OAuthIdentity
    {
        $subject = $claims->string('sub');

        if (null === $subject || !self::isUsableSubject($subject)) {
            throw new OAuthFailedException('id_token carried no usable sub');
        }

        $email = $claims->string('email');

        return new OAuthIdentity(
            $this->provider,
            $subject,
            '' === $email ? null : $email,
            self::isVerified($claims->claim('email_verified')),
        );
    }

    /**
     * The subject is the primary key of the identity: `user_identity` is
     * UNIQUE(provider, provider_user_id), so whatever comes back here decides
     * which account a returning visitor lands on. That makes "is this a usable
     * identifier" a security question rather than a tidiness one.
     *
     * Empty is refused for the obvious reason — every such user would collapse
     * onto a single row, and the second one to sign in would be handed the
     * first one's account. The two less obvious refusals are there because they
     * collapse the same way:
     *
     * - **Surrounding whitespace.** `"123"` and `" 123 "` are two rows for one
     *   provider account, so the SAME person could arrive at two different local
     *   accounts depending on which spelling the provider sent. Refused rather
     *   than trimmed: the stored value has to be what the provider sends, byte
     *   for byte, and quietly rewriting it here would put a second normalising
     *   step between the provider and the unique index.
     * - **C0 control characters, NUL above all.** A NUL is a truncation point
     *   for a great deal of software that is not PHP — logs, monitoring, the
     *   occasional database driver. `"1\0a"` and `"1\0b"` are distinct here and
     *   may not be somewhere downstream, and "distinct here, equal there" is the
     *   exact shape of an identity collision.
     *
     * Neither Google (a decimal string) nor Apple (an opaque token) sends
     * anything this rejects, which is the point: the rule only ever fires on a
     * token no real provider minted.
     */
    private static function isUsableSubject(string $subject): bool
    {
        return '' !== $subject
            && $subject === trim($subject)
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $subject);
    }

    /**
     * Google sends a JSON boolean; Apple sends the string "true".
     *
     * Those two spellings are the entire accepted set, and everything else —
     * `false`, `"false"`, `1`, `"1"`, `"TRUE"`, `null`, an absent claim, an
     * array — reads as NOT verified. Each of those is a deliberate call, not an
     * oversight, and the reasoning is the same for all of them: the two mistakes
     * are not symmetric. Reading a verified address as unverified turns an
     * account link into an ordinary new signup, which is an inconvenience.
     * Reading an unverified address as verified lets whoever typed it claim the
     * local account that already owns it, which is an account takeover. So
     * anything we have not seen a real provider send is refused rather than
     * guessed at.
     *
     * `"TRUE"` in particular is refused rather than case-folded: folding would
     * be a guess about a provider that does not exist, and the guess only ever
     * errs towards trusting more. A cast is refused for the same reason —
     * `(bool) "false"` is true, and that one character is the difference between
     * "this address was verified" and "this address was typed in by whoever is
     * signing in".
     *
     * Read from the raw claim rather than through a typed accessor because the
     * accepted set is a trust decision, not a matter of type — see
     * {@see IdTokenClaims::claim()}.
     */
    private static function isVerified(mixed $value): bool
    {
        return true === $value || 'true' === $value;
    }
}
