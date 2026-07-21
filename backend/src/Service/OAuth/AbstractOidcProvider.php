<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\OAuthFailedException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The half of an OpenID Connect provider that is identical for Google and
 * Apple: POST the authorization code to the token endpoint, then read the
 * identity out of the returned ID token.
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
 * Three properties have to hold for that sentence to apply to this code, and
 * each is enforced here rather than assumed:
 *
 * 1. **The endpoint is ours, not the token's.** getTokenEndpoint() is a
 *    constant in each subclass. No claim, header or request parameter picks
 *    it, so a token cannot nominate the authority that vouches for it.
 * 2. **The connection really is validated TLS.** `verify_peer` and
 *    `verify_host` are restated on the request even though they are Symfony's
 *    defaults, and a non-`https` endpoint is refused before the request is
 *    made. A future `framework.http_client.default_options` edit in another
 *    file therefore cannot withdraw the premise this class stands on.
 * 3. **The communication is direct.** `max_redirects` is 0. A followed
 *    redirect would mean the bytes arrived from a host other than the one we
 *    pinned, which is precisely the case the spec excludes.
 *
 * The boundary is structural, not a convention: readIdentity() and every
 * claim-reading helper below are `private`, and exchangeCode() — the only door
 * into them — is `final` and starts by fetching the token itself. A subclass
 * cannot hand this class a token it got from somewhere else, because there is
 * no method that accepts one. If a future task ever needs to read an ID token
 * that did NOT come from the token endpoint — Apple's `form_post` callback
 * carries one, for instance — that task must verify the signature against the
 * provider's JWKS in its own code. It cannot route around this by calling in
 * here, and that is deliberate.
 *
 * What is still checked, because TLS says nothing about it: `aud` and `azp`
 * (the token was minted for us and issued to us), `iss` (it came from the
 * expected issuer), `exp` (it is current), and `nonce` (it belongs to the flow
 * this browser started).
 */
abstract class AbstractOidcProvider implements OAuthProviderInterface
{
    /**
     * A token whose `exp` has just passed is not evidence of an attack — it is
     * evidence of clock drift between us and the provider. A small tolerance
     * avoids failing honest logins; anything larger would meaningfully extend
     * the life of a captured token.
     */
    private const CLOCK_SKEW_SECONDS = 60;

    /**
     * Inactivity timeout and total wall-clock budget for the token call. The
     * second one matters: `timeout` alone resets on every byte, so a provider
     * that dribbles a response can hold a PHP-FPM worker indefinitely.
     */
    private const REQUEST_TIMEOUT_SECONDS = 10;
    private const REQUEST_MAX_DURATION_SECONDS = 15;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly string $backendBaseUrl,
    ) {
    }

    /**
     * MUST be an `https://` URL and MUST NOT be derived from anything in the
     * request — see the class docblock for why that is load-bearing here.
     */
    abstract protected function getTokenEndpoint(): string;

    /**
     * Accepted `iss` values. A list because Google mints tokens with both
     * `https://accounts.google.com` and the bare `accounts.google.com`.
     *
     * @return list<string>
     */
    abstract protected function getIssuers(): array;

    abstract protected function getClientId(): string;

    abstract protected function getClientSecret(): string;

    /**
     * The redirect URI, built from configuration rather than from the incoming
     * request.
     *
     * This must never be derived from the Host header. The value is echoed to
     * the token endpoint and must match what is registered with the provider
     * byte for byte; deriving it from a header an attacker can set would, on a
     * server that does not pin its host, turn into a redirect of the
     * authorization code to somewhere else.
     */
    final public function getRedirectUri(): string
    {
        return rtrim($this->backendBaseUrl, '/') . '/api/auth/oauth/' . $this->getName() . '/callback';
    }

    final public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity
    {
        if ('' === $nonce) {
            // Not a provider failure but a caller bug, and a dangerous one: the
            // nonce check below is an equality test, and '' === '' is true, so
            // an empty expectation would silently accept a token carrying an
            // empty nonce. Refused here so that comparison is never asked to
            // defend a value that cannot defend itself.
            throw new OAuthFailedException('no nonce to check the id_token against');
        }

        return $this->readIdentity($this->requestIdToken($code, $codeVerifier), $nonce);
    }

    private function requestIdToken(string $code, string $codeVerifier): string
    {
        $endpoint = $this->getTokenEndpoint();
        if (!str_starts_with($endpoint, 'https://')) {
            // The signature exemption is only available over validated TLS.
            // Without it nothing is left attesting who minted the token, so the
            // request is never made rather than made and half-trusted.
            throw new OAuthFailedException('token endpoint is not https');
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'redirect_uri' => $this->getRedirectUri(),
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                ],
                // The three options this class's security argument rests on.
                // See the class docblock; they are restated here rather than
                // left to global defaults on purpose.
                'verify_peer' => true,
                'verify_host' => true,
                'max_redirects' => 0,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'max_duration' => self::REQUEST_MAX_DURATION_SECONDS,
            ]);

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            // Covers transport failures, every non-2xx and an undecodable body,
            // since toArray() throws on all three. The provider's own error
            // code is useful in a log and useless — or worse — in a response.
            throw new OAuthFailedException('token endpoint call failed', $e);
        }

        $idToken = $payload['id_token'] ?? null;
        if (!\is_string($idToken) || '' === $idToken) {
            throw new OAuthFailedException('token response carried no id_token');
        }

        return $idToken;
    }

    /**
     * @param non-empty-string $expectedNonce
     */
    private function readIdentity(string $idToken, string $expectedNonce): OAuthIdentity
    {
        $claims = $this->decodeClaims($idToken);

        $issuer = $claims['iss'] ?? null;
        if (!\is_string($issuer) || !\in_array($issuer, $this->getIssuers(), true)) {
            throw new OAuthFailedException('id_token issuer mismatch');
        }

        if (!$this->audienceMatches($claims['aud'] ?? null)) {
            throw new OAuthFailedException('id_token audience mismatch');
        }

        // OIDC Core §3.1.3.7 item 5: when `azp` is present it names the client
        // the token was issued to, which may differ from the audience. Google
        // omits it in the single-client case and Apple never sends it, so in
        // practice this only ever fires on a token that took a detour.
        //
        // Item 4's companion SHOULD — require `azp` to be PRESENT whenever
        // `aud` has several values — is deliberately not implemented. It exists
        // to tell you who presented a token when several parties could have,
        // and here only one party ever can: we fetch the token ourselves, from
        // an endpoint we hardcode, authenticated with our own client secret.
        // Enforcing it would reject nothing an attacker can send and would
        // break the day a provider starts adding a second audience.
        $authorizedParty = $claims['azp'] ?? null;
        if (
            null !== $authorizedParty
            && (!\is_string($authorizedParty) || !hash_equals($this->getClientId(), $authorizedParty))
        ) {
            throw new OAuthFailedException('id_token authorized party mismatch');
        }

        // `is_int`, not `is_numeric`: RFC 7519 defines `exp` as a JSON number,
        // and a token spelling it "1780000000" was not built by the provider.
        // Coercing it would mean accepting a shape only a forger produces.
        $expiry = $claims['exp'] ?? null;
        if (!\is_int($expiry) || $expiry + self::CLOCK_SKEW_SECONDS < $this->clock->now()->getTimestamp()) {
            throw new OAuthFailedException('id_token expired or has no exp');
        }

        $nonce = $claims['nonce'] ?? null;
        if (!\is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            // The one check that ties this token to the browser that started
            // the flow. Without it, a token obtained anywhere could be replayed
            // into somebody else's callback. hash_equals rather than === so the
            // comparison cannot be walked character by character.
            throw new OAuthFailedException('id_token nonce mismatch');
        }

        $subject = $claims['sub'] ?? null;
        if (!\is_string($subject) || !self::isUsableSubject($subject)) {
            throw new OAuthFailedException('id_token carried no usable sub');
        }

        $email = $claims['email'] ?? null;

        return new OAuthIdentity(
            $this->getName(),
            $subject,
            \is_string($email) && '' !== $email ? $email : null,
            self::readBoolClaim($claims['email_verified'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeClaims(string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (3 !== \count($segments)) {
            throw new OAuthFailedException('id_token is not a three-segment JWT');
        }

        $decoded = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if (false === $decoded) {
            throw new OAuthFailedException('id_token payload is not valid base64url');
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($decoded, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OAuthFailedException('id_token payload is not valid JSON', $e);
        }

        if (!\is_array($claims)) {
            throw new OAuthFailedException('id_token payload is not a JSON object');
        }

        // A JSON *array* also decodes to a PHP array, and would then sail into
        // the claim reads and fail there by luck rather than by decision. The
        // key check is what makes the return type below true rather than hoped.
        foreach (array_keys($claims) as $key) {
            if (!\is_string($key)) {
                throw new OAuthFailedException('id_token payload is a JSON array, not an object');
            }
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * `aud` is a string or an array of strings per RFC 7519 §4.1.3.
     *
     * hash_equals is not here because a client id is secret — it is not. It is
     * here so that every identity comparison in this class reads the same way,
     * and so nobody later has to work out which of them were the sensitive
     * ones. The cost is a function call.
     */
    private function audienceMatches(mixed $audience): bool
    {
        $clientId = $this->getClientId();

        if (\is_string($audience)) {
            return hash_equals($clientId, $audience);
        }

        if (!\is_array($audience)) {
            return false;
        }

        foreach ($audience as $candidate) {
            // Non-string members (a nested array, a number) simply do not
            // match; there is no shape of `aud` that can match by accident.
            if (\is_string($candidate) && hash_equals($clientId, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subject is the primary key of the identity: `user_identity` is
     * UNIQUE(provider, provider_user_id), so whatever comes back here decides
     * which account a returning visitor lands on. That makes "is this a usable
     * identifier" a security question rather than a tidiness one.
     *
     * Empty is refused for the obvious reason — every such user would collapse
     * onto a single row, and the second one to sign in would be handed the
     * first one's account. The two less obvious refusals are there because
     * they collapse the same way:
     *
     * - **Surrounding whitespace.** `"123"` and `" 123 "` are two rows for one
     *   provider account, so the SAME person could arrive at two different
     *   local accounts depending on which spelling the provider sent. Refused
     *   rather than trimmed: the stored value has to be what the provider
     *   sends, byte for byte, and quietly rewriting it here would put a second
     *   normalising step between the provider and the unique index.
     * - **C0 control characters, NUL above all.** A NUL is a truncation point
     *   for a great deal of software that is not PHP — logs, monitoring, the
     *   occasional database driver. `"1\0a"` and `"1\0b"` are distinct here and
     *   may not be somewhere downstream, and "distinct here, equal there" is
     *   the exact shape of an identity collision.
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
     * oversight, and the reasoning is the same for all of them: the two
     * mistakes are not symmetric. Reading a verified address as unverified
     * turns an account link into an ordinary new signup, which is an
     * inconvenience. Reading an unverified address as verified lets whoever
     * typed it claim the local account that already owns it, which is an
     * account takeover. So anything we have not seen a real provider send is
     * refused rather than guessed at.
     *
     * `"TRUE"` in particular is refused rather than case-folded: folding would
     * be a guess about a provider that does not exist, and the guess only ever
     * errs towards trusting more. A cast is refused for the same reason —
     * `(bool) "false"` is true, and that one character is the difference
     * between "this address was verified" and "this address was typed in by
     * whoever is signing in".
     */
    private static function readBoolClaim(mixed $value): bool
    {
        return true === $value || 'true' === $value;
    }
}
