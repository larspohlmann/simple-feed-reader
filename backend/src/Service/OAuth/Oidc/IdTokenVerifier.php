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
 * Read the carve-out narrowly: it buys only the assurance that the token came
 * from the issuer, and only for a token pulled straight off the token endpoint
 * over TLS we validated. It is not a general permission to skip signatures.
 *
 * ## What keeps that precondition true
 *
 * verify() takes an {@see IdToken}, never a string. That type means "fetched
 * from a token endpoint, over validated TLS, with no redirect in between", and
 * {@see TokenEndpoint::fetch()} — which enforces all three — is the only place
 * that constructs one, so a raw JWT from another channel cannot reach this
 * class by mistake.
 *
 * That boundary used to be `private` methods on the provider; it is now the
 * parameter type, checked at every call site, plus OidcBoundaryTest, which
 * fails the build if a second IdToken construction site appears. Neither form
 * stops someone deliberately writing `new IdToken($jwt)` around a token from
 * elsewhere. A future ID token that did not come from the token endpoint —
 * Apple's `form_post` callback carries one — must be verified against the
 * provider's JWKS in its own code; wrapping it in an IdToken to reuse this
 * class would skip the only check standing behind that channel.
 *
 * ## What is checked, because TLS says nothing about it
 *
 * `aud`/`azp` (minted for us, issued to us), `iss` (expected issuer), `exp`
 * (current), `nonce` (belongs to this browser's flow), and `sub` (names an
 * identity we can store without collisions) — one small guard each, below, in
 * that order. Every guard raises the same OAuthFailedException, since the
 * caller must not be able to tell "no such client" from "expired token"; only
 * $logDetail differs.
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
            // The nonce check below is an equality test, and '' === '' is
            // true, so an empty expectation would silently accept a token
            // carrying an empty nonce. AbstractOidcProvider::exchangeCode()
            // already refuses this before calling the token endpoint; this is
            // the backstop that holds regardless of who constructed this
            // verifier.
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
     * hash_equals is not here because a client id is secret — it isn't. It
     * keeps every identity comparison in this class reading the same way, so
     * nobody later has to work out which ones were the sensitive ones. The
     * cost is a function call.
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
     * OIDC Core §3.1.3.7 item 5: when present, `azp` names the client the
     * token was issued to, which may differ from `aud`. Google omits it for a
     * single client and Apple never sends it, so in practice this only fires
     * on a token that took a detour.
     *
     * Item 4's companion SHOULD — require `azp` when `aud` has several values
     * — is deliberately not implemented: it tells you who presented a token
     * when several parties could have, and here only one party ever can (we
     * fetch the token ourselves, from a hardcoded endpoint, with our own
     * client secret). Enforcing it would reject nothing an attacker can send,
     * and would break the day a provider adds a second audience.
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
     * The one check that ties this token to the browser that started the
     * flow: without it, a token obtained anywhere could be replayed into
     * somebody else's callback. hash_equals rather than === so the
     * comparison can't be walked character by character.
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
     * which account a returning visitor lands on — a security question, not a
     * tidiness one.
     *
     * Empty is refused for the obvious reason: every such user would collapse
     * onto one row, and the second to sign in would be handed the first one's
     * account. Two less obvious refusals collapse the same way:
     *
     * - **Surrounding whitespace.** `"123"` and `" 123 "` are two rows for one
     *   provider account, so the same person could land on either depending
     *   on which spelling the provider sent. Refused rather than trimmed: the
     *   stored value must be what the provider sends, byte for byte, with no
     *   normalising step between the provider and the unique index.
     * - **C0 control characters, NUL above all.** A NUL truncates in a lot of
     *   software that isn't PHP — logs, monitoring, some database drivers.
     *   `"1\0a"` and `"1\0b"` are distinct here and may not be downstream,
     *   which is the exact shape of an identity collision.
     *
     * Neither Google (a decimal string) nor Apple (an opaque token) sends
     * anything this rejects — the rule only ever fires on a token no real
     * provider minted.
     */
    private static function isUsableSubject(string $subject): bool
    {
        return '' !== $subject
            && $subject === trim($subject)
            && 1 !== preg_match('/[\x00-\x1F\x7F]/', $subject);
    }

    /**
     * Google sends a JSON boolean; Apple sends the string "true". Those two
     * spellings are the entire accepted set — `false`, `"false"`, `1`, `"1"`,
     * `"TRUE"`, `null`, an absent claim, an array all read as NOT verified.
     * The two mistakes are not symmetric: reading verified as unverified just
     * turns an account link into a new signup, but reading unverified as
     * verified lets whoever typed the address claim the account that already
     * owns it — an account takeover. So anything not seen from a real
     * provider is refused rather than guessed at.
     *
     * `"TRUE"` is refused rather than case-folded — folding would be a guess
     * about a provider that doesn't exist, always erring toward more trust. A
     * cast is refused for the same reason: `(bool) "false"` is true, and that
     * one character is the difference between "verified" and "typed in by
     * whoever is signing in".
     *
     * Read from the raw claim, not a typed accessor, because the accepted set
     * is a trust decision, not a matter of type — see
     * {@see IdTokenClaims::claim()}.
     */
    private static function isVerified(mixed $value): bool
    {
        return true === $value || 'true' === $value;
    }
}
