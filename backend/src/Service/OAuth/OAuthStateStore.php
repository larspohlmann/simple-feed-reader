<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthStartState;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Holds the per-flow secrets between the redirect to the provider and the
 * provider's callback.
 *
 * Server-side, and not in a session, because the API is stateless and issues no
 * session cookie at all.
 *
 * ## `state` alone is not enough, and this class used to claim otherwise
 *
 * On its own, `state` is an unguessable value that must have been issued by
 * this server, is destroyed on first use, and expires in ten minutes. Note what
 * that proves: *this server started some flow*. It does NOT prove *this browser
 * started this flow* — and the second property is the one that stops login
 * CSRF.
 *
 * An earlier version of this docblock asserted that `state` alone "stops an
 * attacker from feeding their own authorization code into a victim's browser".
 * It does not, and cannot. The attack it fails to stop: an attacker with a real
 * account scripts the start endpoint, keeps `state`, approves at the provider,
 * and captures `code` from the provider's final redirect WITHOUT following it,
 * so the state is never burned. They then get a victim to open the callback
 * URL. Both values are genuine and unspent, so every check above passes, and
 * the victim's browser ends up authenticated AS THE ATTACKER — every feed they
 * add and every article they read landing in the attacker's account. It was
 * proved by driving the endpoints with an empty cookie jar before it was fixed.
 *
 * ## The binding
 *
 * So `start()` also mints a `browserToken`, which the controller sets as a
 * cookie, and stores only its DIGEST beside the flow. `consume()` requires the
 * matching token back. A callback that cannot produce it is refused as though
 * the state were unknown.
 *
 * Only a digest is stored, and compared with hash_equals, for the same reason
 * the state itself is only ever a hashed cache key: for the ten minutes a flow
 * is live the token is a bearer credential, and a readable cache directory must
 * not be a list of usable ones.
 *
 * The token is minted here rather than accepted from the caller. Taking a
 * caller-supplied value would let an attacker pin the binding to something they
 * already know, which is the whole property being bought.
 *
 * **The cookie must be `SameSite=None`, and that is not a weakening.** Apple's
 * callback is a cross-site POST (`response_mode=form_post`); a `Lax` cookie is
 * not sent on a cross-site POST, so `Lax` would leave Google working perfectly
 * and Apple failing every sign-in with `invalid_state`. `None` requires
 * `Secure`. The confidentiality the `SameSite` attribute would have provided is
 * supplied instead by the value being unguessable and single-use, and by the
 * `__Host-` prefix, which forbids a `Domain` attribute and so keeps any other
 * host — including a compromised sibling — from writing this cookie into the
 * backend's origin. See OAuthController for the attributes as sent.
 *
 * ONE FLOW PER BROWSER AT A TIME. There is one cookie name, so starting a
 * second sign-in overwrites the first flow's binding and the abandoned tab
 * fails with `invalid_state`. That is the correct trade: the alternative is
 * keeping a set of live bindings, which means an unauthenticated endpoint a
 * stranger can call writes unboundedly to the browser. Somebody who opened two
 * consent screens starts again; nobody is signed into the wrong account.
 *
 * SINGLE USE IS BEST-EFFORT UNDER CONCURRENCY — read this before relying on it.
 * consume() deletes the entry before validating it, which means a state that
 * fails the expiry check is still burned rather than left available to retry.
 * It does NOT make redemption atomic. PSR-6 offers no compare-and-swap, and
 * `deleteItem()` returns true whether or not the key existed, so it cannot be
 * pressed into service as one either. Two callbacks arriving together can both
 * see `isHit()`, both delete, and both be handed the same OAuthStartState. The
 * ordering narrows the window to the gap between getItem() and deleteItem();
 * it does not close it.
 *
 * That is deliberate rather than overlooked. Both racers go on to spend the
 * SAME authorization code at the provider's token endpoint, and that code is
 * single-use at the provider — so the second exchange fails there, on the
 * authority of the party that issued it. The race therefore costs a wasted
 * round trip and cannot produce two sessions, and nothing in it crosses a user
 * boundary: both racers are the same browser completing the same flow. Closing
 * it would mean taking a lock on every callback, which on the shared hosting
 * this deploys to is a real per-request cost paid against no real threat.
 *
 * The property this class actually guarantees is therefore: a state is
 * unguessable, is issued by us, expires, and cannot be redeemed twice in
 * sequence. Anything stronger belongs to the provider's own code single-use
 * rule, not to this cache.
 */
final readonly class OAuthStateStore
{
    /** Public so OAuthController can size the flow cookie to outlive it. */
    public const LIFETIME_SECONDS = 600;
    private const KEY_PREFIX = 'oauth_state_';

    public function __construct(
        private CacheItemPoolInterface $oauthStateCache,
        private ClockInterface $clock,
    ) {
    }

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
        // Note what is absent: the state itself, and the browser token. Both
        // are bearer credentials while the flow is live; the state is the
        // lookup key (hashed) and the token is stored only as a digest, so a
        // readable cache file yields neither a usable state nor a usable
        // binding.
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
     * start this flow — because the callback must not report which.
     *
     * The binding failure is deliberately NOT distinguishable from the others.
     * Collapsing them into one null is the whole point: a caller who could tell
     * "wrong cookie" from "no such state" could probe for live states, and the
     * controller has no error code for it either.
     *
     * @param string|null $browserToken the flow cookie the callback arrived
     *                                  with, or null if it arrived with none —
     *                                  which is itself a failure, not a bypass
     *
     * See the class docblock for what "single use" does and does not promise
     * when two callbacks arrive at once.
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

        $stored = $item->get();
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

        // The browser binding. A callback that cannot produce the token this
        // flow was started with is refused here — that refusal is what makes
        // this class's promise "this browser started this flow" rather than
        // "this server started some flow". See the class docblock for the login
        // CSRF this closes.
        //
        // Note the position: AFTER the deleteItem() above, so a wrong token
        // burns the state rather than leaving it live to be guessed against
        // again. hash_equals because the stored value is a secret-derived
        // digest and a byte-at-a-time comparison leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            return null;
        }

        // The pool's own TTL should have removed it already. This check exists
        // because that TTL is enforced by the cache backend's clock, while the
        // rest of the application — and every test — runs on the injected one.
        // Belt and braces, and it makes the expiry testable.
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
     * base64url(sha256(verifier)), the `S256` method of RFC 7636 4.2. The
     * plain method is not offered: it would put the verifier in the redirect
     * URL, which is the exact exposure PKCE exists to remove.
     */
    private static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * The cache key is a digest, not the state itself. For the ten minutes a
     * flow is live the state value is a bearer credential, and cache entries
     * on shared hosting are files on a disk we do not own exclusively — a
     * directory listing should not be a list of usable states.
     *
     * Unsalted SHA-256 is sufficient here and bcrypt would not be: the input is
     * 32 bytes from random_bytes(), so there is no guessable preimage to
     * protect and no reason to pay a work factor on every callback.
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

    private static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
