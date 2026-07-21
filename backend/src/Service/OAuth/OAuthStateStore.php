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
 * Server-side, and not in a session, for two reasons. The API is stateless and
 * issues no session cookie at all; and Apple's callback is a cross-site POST
 * (`response_mode=form_post`), which a SameSite=Lax cookie would not accompany
 * — so a session-backed store would work for Google and mysteriously fail for
 * Apple.
 *
 * What `state` buys us, given there is no session to bind to: it is an
 * unguessable value that must have been issued by this server, is destroyed on
 * first use, and expires in ten minutes. A callback carrying a code but no
 * valid state is discarded, which is what stops an attacker from feeding their
 * own authorization code into a victim's browser and silently linking the
 * victim's session to the attacker's provider account.
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
    private const LIFETIME_SECONDS = 600;
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

        $started = new OAuthStartState(
            $provider,
            $state,
            $nonce,
            $codeVerifier,
            self::challengeFor($codeVerifier),
        );

        $item = $this->oauthStateCache->getItem(self::keyFor($state));
        // Note what is absent: the state itself. It is the lookup key (hashed)
        // and nothing more, so a readable cache file yields no usable state.
        $item->set([
            'provider' => $provider,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->oauthStateCache->save($item);

        return $started;
    }

    /**
     * Redeems a state value, destroying it. Returns null for every failure —
     * unknown, already used, expired — because the callback must not report
     * which.
     *
     * See the class docblock for what "single use" does and does not promise
     * when two callbacks arrive at once.
     */
    public function consume(string $state): ?OAuthStartState
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
            || !\is_int($stored['expires_at'] ?? null)
        ) {
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
        return self::KEY_PREFIX . hash('sha256', $state);
    }

    private static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
