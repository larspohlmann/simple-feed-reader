<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * The handover between the provider callback and the SPA.
 *
 * The callback finishes holding an authenticated user but must answer with a
 * redirect — and a JWT in that query string would land in browser history,
 * `Referer` headers, and every proxy log in between. So the redirect carries
 * a code instead: worthless after 30 seconds or after one use, and the SPA
 * POSTs it back for the real token. 30 seconds is generous for "browser
 * follows a redirect, SPA boots" and short enough that a code captured from
 * a log is almost always dead on arrival. The window runs from issue and is
 * never extended by a read; refreshing on access would let a leaked code
 * live indefinitely while something kept touching it.
 *
 * The stored value is a user id, not a JWT: minting the token at exchange
 * time means its `iat` reflects when the session began, which matters
 * because password changes revoke tokens by comparing `iat` against
 * User::$passwordChangedAt.
 *
 * ## The code is NOT a bearer value, and used to be
 *
 * A short life and single use bound how long a leaked code was worth
 * stealing, not who could spend one — a different property, exactly as
 * `state` proving "this server started a flow" differs from "this browser
 * started it" (see OAuthStateStore).
 *
 * The attack the short window did not close: an attacker completes a genuine
 * sign-in in their own browser — forced by OAuthStateStore's binding — then
 * withholds the code. Inside its 30 seconds they point a victim at
 * `<frontend>/auth/callback?code=X`; the SPA exchanges on landing with no
 * user gesture, and the victim's browser ends up holding the ATTACKER's JWT,
 * landing every feed and article in the attacker's account. Thirty seconds
 * is ample, and it scripts.
 *
 * So the code carries the same browser binding the flow does. issue() stores
 * only the digest of the flow token the callback authenticated; consume()
 * requires the matching token back and compares with hash_equals. A missing
 * or wrong binding is null, indistinguishable from unknown, spent or
 * expired — telling them apart would confirm a captured code was live. The
 * binding is the flow cookie the browser already holds, not a second
 * secret, so there is one cookie name, one set of attributes, one lifetime
 * to keep in sync. See OAuthController::FLOW_COOKIE.
 *
 * SINGLE USE IS BEST-EFFORT UNDER CONCURRENCY — same caveat as
 * OAuthStateStore, same reason. consume() deletes before validating, so an
 * expired entry cannot be retried, but redemption is not atomic: PSR-6 has
 * no compare-and-swap, and `deleteItem()` returns true whether or not the
 * key existed. Two exchanges arriving together can both see `isHit()`, both
 * delete, and both get the same user id — the ordering narrows the window
 * between getItem() and deleteItem() but does not close it. Left unclosed
 * deliberately: the failure mode is one user receiving two JWTs instead of
 * one, and that user was entitled to a JWT. Nothing crosses a user boundary
 * — the code is unguessable and both racers already held it, a second token
 * worth no more than the first, expiring on the same schedule. A lock on
 * every exchange would buy nothing on shared hosting but a round trip.
 *
 * Guarantees: the code is unguessable, stored only as a digest, expires 30
 * seconds after issue regardless of reads, and cannot be redeemed twice in
 * sequence.
 */
final readonly class LoginCodeStore
{
    /** Public so OAuthController can size the flow cookie to outlive it. */
    public const int LIFETIME_SECONDS = 30;
    private const string KEY_PREFIX = 'oauth_login_code_';

    public function __construct(
        private CacheItemPoolInterface $loginCodeCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $browserToken the flow binding the callback arrived with —
     *                             the same value consume() will require back
     *
     * @throws RandomException
     * @throws InvalidArgumentException
     */
    public function issue(int $userId, string $browserToken): string
    {
        $code = bin2hex(random_bytes(32));

        $item = $this->loginCodeCache->getItem(self::keyFor($code));
        // Note what is absent: the code and the browser token. The code is only
        // the hashed lookup key, and the token is stored only as a digest, so a
        // readable cache file yields neither a usable code nor a binding.
        $item->set([
            'user_id' => $userId,
            'browser_digest' => self::digest($browserToken),
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->loginCodeCache->save($item);

        return $code;
    }

    /**
     * @param string|null $browserToken the flow cookie the exchange arrived
     *                                  with, or null if it arrived with none —
     *                                  which is itself a failure, not a bypass
     *
     * @return int|null the user id, or null if the code is unknown, spent,
     *                  expired, or presented by a browser that did not complete
     *                  the flow — the caller must not distinguish those
     * See the class docblock for what "single use" does and does not promise
     * when two exchanges arrive at once.
     * @throws InvalidArgumentException
     */
    public function consume(string $code, ?string $browserToken): ?int
    {
        $key = self::keyFor($code);
        $item = $this->loginCodeCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        // Deleted before the checks below, so an expired entry is burned rather
        // than left available to retry. This does not make redemption atomic —
        // see the class docblock.
        $this->loginCodeCache->deleteItem($key);

        $stored = $item->get();
        if (
            !\is_array($stored)
            || !\is_int($stored['user_id'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            return null;
        }

        // The browser binding, and the reason this class is not a bearer-token
        // store. See the class docblock for the login CSRF it closes.
        //
        // Positioned AFTER the deleteItem() above, so a wrong token burns the
        // code rather than leaving it live to guess against again. hash_equals
        // because the stored digest is secret-derived and a byte-at-a-time
        // comparison leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            return null;
        }

        // The pool's own TTL should have removed it already. This check exists
        // because that TTL runs on the cache backend's clock while the app — and
        // every test — runs on the injected one. Belt and braces, and it makes
        // the expiry testable.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $stored['user_id'];
    }

    /**
     * Hashed for the same reason ActionToken stores a digest: the code is a
     * bearer credential, and the pool is a directory of files.
     *
     * Unsalted SHA-256 rather than a password hash, as in OAuthStateStore: the
     * input is 32 bytes from random_bytes(), so there is no guessable preimage
     * to protect and no reason to pay a work factor on every exchange.
     */
    private static function keyFor(string $code): string
    {
        return self::KEY_PREFIX . self::digest($code);
    }

    /**
     * The one hash used for both the cache key and the browser binding, so the
     * two cannot drift apart. Unsalted SHA-256 for the reason given above:
     * every input is 32 bytes from random_bytes().
     */
    private static function digest(string $value): string
    {
        return hash('sha256', $value);
    }
}
