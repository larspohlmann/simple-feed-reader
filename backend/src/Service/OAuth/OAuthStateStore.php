<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthStartState;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Holds the per-flow secrets between the redirect to the provider and the
 * provider's callback. Server-side and not in a session, because the API is
 * stateless and issues no session cookie.
 *
 * ## `state` alone is not enough
 *
 * On its own `state` is unguessable, was issued by this server, is destroyed on
 * first use, and expires in ten minutes — which proves only *this server started
 * some flow*, not *this browser started this flow*. The gap is login CSRF: an
 * attacker with a real account scripts start(), keeps `state`, approves at the
 * provider and captures `code` from the final redirect WITHOUT following it (so
 * the state is never burned), then gets a victim to open the callback URL. Both
 * values are genuine and unspent, every check passes, and the victim's browser
 * ends up authenticated AS THE ATTACKER. Proved by driving the endpoints with an
 * empty cookie jar.
 *
 * ## The binding
 *
 * start() also mints a `browserToken`, set as a cookie by the controller, and
 * stores only its DIGEST beside the flow; consume() requires the matching token
 * back and refuses a callback that cannot produce it as though the state were
 * unknown. Only a digest is stored, compared with hash_equals, for the same
 * reason the state is only ever a hashed cache key: while a flow is live the
 * token is a bearer credential, and a readable cache directory must not be a list
 * of usable ones. The token is minted here, not accepted from the caller, or an
 * attacker could pin the binding to a value they already know.
 *
 * **The cookie must be `SameSite=None`, and that is not a weakening.** Apple's
 * callback is a cross-site POST (`response_mode=form_post`), which a `Lax` cookie
 * is not sent on — so `Lax` would fail every Apple sign-in with `invalid_state`.
 * `None` requires `Secure`; the confidentiality `SameSite` would give is supplied
 * instead by the value being unguessable and single-use and by the `__Host-`
 * prefix, which forbids a `Domain` attribute so no other host can write this
 * cookie into the backend's origin. See OAuthController for the attributes.
 *
 * ONE FLOW PER BROWSER AT A TIME. One cookie name, so a second sign-in overwrites
 * the first flow's binding and the abandoned tab fails with `invalid_state`. The
 * alternative — a set of live bindings — lets a stranger calling an
 * unauthenticated endpoint write unboundedly to the browser. Somebody who opened
 * two consent screens just starts again.
 *
 * SINGLE USE IS BEST-EFFORT UNDER CONCURRENCY. consume() deletes the entry before
 * validating it, so a state that fails a check is still burned, but redemption is
 * not atomic: PSR-6 offers no compare-and-swap, so two callbacks arriving
 * together can both see isHit(), both delete, and both get the same
 * OAuthStartState. That is deliberate: both racers then spend the SAME
 * authorization code at the provider, which is single-use there, so the second
 * exchange fails on the provider's authority; the race wastes a round trip,
 * cannot produce two sessions, and never crosses a user boundary. Closing it
 * would mean a lock on every callback — a real per-request cost on shared hosting
 * against no real threat.
 */
final readonly class OAuthStateStore
{
    /** Public so OAuthController can size the flow cookie to outlive it. */
    public const int LIFETIME_SECONDS = 600;
    private const string KEY_PREFIX = 'oauth_state_';

    public function __construct(
        private CacheItemPoolInterface $oauthStateCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function start(string $provider): OAuthStartState
    {
        $state = self::randomToken();
        $nonce = self::randomToken();

        // PKCE verifier: 43-128 unreserved characters per RFC 7636 4.1.
        // 32 random bytes hex-encoded is 64, comfortably inside that range and
        // free of any character needing escaping in a form-encoded body.
        $codeVerifier = self::randomToken();

        // Goes to the browser in a cookie, never to the provider. See the class
        // docblock: this is what makes `state` mean "this browser".
        $browserToken = self::randomToken();

        $started = new OAuthStartState(
            $provider,
            $state,
            $nonce,
            $codeVerifier,
            self::challengeFor($codeVerifier),
            $browserToken,
        );

        $item = $this->oauthStateCache->getItem(self::keyFor($state));
        // Neither the state nor the browser token is stored in readable form: the
        // state is the hashed key and the token only a digest, so a readable cache
        // file yields neither a usable state nor a usable binding.
        $item->set([
            'provider' => $provider,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'browser_digest' => self::digest($browserToken),
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->oauthStateCache->save($item);

        return $started;
    }

    /**
     * Redeems a state value, destroying it. Returns null for every failure —
     * unknown, already used, expired, or presented by a browser that did not
     * start this flow — because the callback must not report which: a caller who
     * could tell "wrong cookie" from "no such state" could probe for live states.
     *
     * @param string|null $browserToken the flow cookie the callback arrived
     *                                  with, or null if it arrived with none —
     *                                  which is itself a failure, not a bypass
     *
     * @throws InvalidArgumentException
     */
    public function consume(string $state, ?string $browserToken): ?OAuthStartState
    {
        $key = self::keyFor($state);
        $item = $this->oauthStateCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        // Deleted before any validation below, so a state that fails the
        // expiry check is still burned rather than left available to retry.
        $this->oauthStateCache->deleteItem($key);

        $stored = self::decodeStored($item->get());
        if (null === $stored) {
            return null;
        }

        // The browser binding: a callback that cannot produce the token this flow
        // started with is refused, which is what makes the promise "this browser
        // started this flow" (see class docblock). Checked AFTER deleteItem() so a
        // wrong token burns the state rather than leaving it live. hash_equals
        // because the stored value is a secret-derived digest and a byte-at-a-time
        // compare leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            return null;
        }

        // The pool's TTL should have removed it already, but that runs on the
        // cache backend's clock while the app and tests use the injected one.
        // Belt and braces, and it makes expiry testable.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        $codeVerifier = $stored['code_verifier'];

        return new OAuthStartState(
            $stored['provider'],
            $state,
            $stored['nonce'],
            $codeVerifier,
            self::challengeFor($codeVerifier),
        );
    }

    /**
     * Validates the shape of a cache entry written by start(), returning it typed
     * or null if anything is missing or wrong-typed — so a corrupt or tampered
     * entry is treated exactly like an unknown state.
     *
     * @return array{
     *     provider: string,
     *     nonce: string,
     *     code_verifier: string,
     *     browser_digest: string,
     *     expires_at: int,
     * }|null
     */
    private static function decodeStored(mixed $stored): ?array
    {
        if (
            !\is_array($stored)
            || !\is_string($stored['provider'] ?? null)
            || !\is_string($stored['nonce'] ?? null)
            || !\is_string($stored['code_verifier'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            return null;
        }

        return [
            'provider' => $stored['provider'],
            'nonce' => $stored['nonce'],
            'code_verifier' => $stored['code_verifier'],
            'browser_digest' => $stored['browser_digest'],
            'expires_at' => $stored['expires_at'],
        ];
    }

    /**
     * base64url(sha256(verifier)), the `S256` method of RFC 7636 4.2. The
     * plain method is not offered: it would put the verifier in the redirect
     * URL, which is the exact exposure PKCE exists to remove.
     */
    private static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * The cache key is a digest, not the state itself: while a flow is live the
     * state is a bearer credential, and cache entries on shared hosting are files
     * we do not own exclusively — a directory listing must not be a list of usable
     * states. Unsalted SHA-256 suffices (the input is 32 bytes from random_bytes,
     * so there is no guessable preimage) and bcrypt would only pay a work factor
     * for nothing.
     */
    private static function keyFor(string $state): string
    {
        return self::KEY_PREFIX . self::digest($state);
    }

    /**
     * The one hash used for both the cache key and the browser binding, so the
     * two cannot drift apart. Unsalted SHA-256 for the reason given above: every
     * input is 32 bytes from random_bytes().
     */
    private static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @throws RandomException
     */
    private static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
