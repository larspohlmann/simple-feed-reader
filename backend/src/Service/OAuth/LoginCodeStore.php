<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * The handover between the provider callback and the SPA.
 *
 * The callback finishes on the server holding an authenticated user, but it
 * must answer with a redirect the browser follows — and a JWT in that
 * redirect's query string would be written into browser history, sent onward
 * in `Referer` headers, and logged verbatim by every proxy and access log in
 * between. So the redirect carries a code that is worthless 30 seconds later
 * and worthless after one use, and the SPA POSTs it back for the real token.
 *
 * 30 seconds is generous for "a browser follows a redirect and the SPA boots",
 * and short enough that a code captured from a log is almost always dead on
 * arrival. The window runs from issue and is never extended by a read; a store
 * that refreshed on access would let a leaked code live indefinitely so long as
 * something kept touching it, which is the exact exposure the short window
 * exists to bound.
 *
 * The stored value is a user id, not a JWT: minting the token at exchange time
 * means its `iat` reflects when the session actually began, which matters
 * because password changes revoke tokens by comparing `iat` against
 * User::$passwordChangedAt.
 *
 * SINGLE USE IS BEST-EFFORT UNDER CONCURRENCY — the same caveat as
 * OAuthStateStore, and for the same reason. consume() deletes before
 * validating, so an expired entry cannot be retried, but redemption is not
 * atomic: PSR-6 offers no compare-and-swap, and `deleteItem()` returns true
 * whether or not the key existed, so it cannot be pressed into service as one.
 * Two exchanges arriving together can both see `isHit()`, both delete, and both
 * be handed the same user id. The ordering narrows the window between
 * getItem() and deleteItem(); it does not close it.
 *
 * Left unclosed deliberately. The failure mode is that one user receives two
 * JWTs instead of one — and that user was entitled to a JWT, which is the whole
 * point of the exchange. Nothing crosses a user boundary, because the code is
 * unguessable and both racers are by definition whoever already held it; a
 * second token is worth no more to them than the first, and both expire on the
 * same schedule. Taking a lock on every exchange would buy nothing on shared
 * hosting except a round trip.
 *
 * What this class does guarantee: the code is unguessable, is stored only as a
 * digest, expires 30 seconds after issue regardless of intervening reads, and
 * cannot be redeemed twice in sequence.
 */
final readonly class LoginCodeStore
{
    private const LIFETIME_SECONDS = 30;
    private const KEY_PREFIX = 'oauth_login_code_';

    public function __construct(
        private CacheItemPoolInterface $loginCodeCache,
        private ClockInterface $clock,
    ) {
    }

    public function issue(int $userId): string
    {
        $code = bin2hex(random_bytes(32));

        $item = $this->loginCodeCache->getItem(self::keyFor($code));
        // Note what is absent: the code itself. It is the lookup key (hashed)
        // and nothing more, so a readable cache file yields no usable code.
        $item->set([
            'user_id' => $userId,
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->loginCodeCache->save($item);

        return $code;
    }

    /**
     * @return int|null the user id, or null if the code is unknown, spent or
     *                  expired — the caller must not distinguish those
     *
     * See the class docblock for what "single use" does and does not promise
     * when two exchanges arrive at once.
     */
    public function consume(string $code): ?int
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
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            return null;
        }

        // The pool's own TTL should have removed it already. This check exists
        // because that TTL runs on the cache backend's clock while the rest of
        // the application — and every test — runs on the injected one. Belt and
        // braces, and it makes the expiry testable.
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
        return self::KEY_PREFIX . hash('sha256', $code);
    }
}
