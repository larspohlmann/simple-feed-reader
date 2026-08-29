<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\UnknownChallengeException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Holds a WebAuthn ceremony's challenge between the moment the options are
 * handed to the browser and the moment its response comes back. Server-side,
 * because the challenge must not be guessable and the API keeps no session.
 *
 * Modelled directly on OAuthStateStore, which the same reasoning about opaque
 * handles and hashed cache keys already applies to:
 *
 * - The handle returned by issue() is a bearer credential for the five
 *   minutes it lives. Only its DIGEST is ever used as the cache key, because a
 *   readable cache directory — files on shared hosting we do not own
 *   exclusively — must not double as a list of usable handles.
 * - consume() deletes the entry before validating it, so a handle that fails
 *   the expiry check is burned rather than left available to retry.
 *
 * SINGLE USE IS BEST-EFFORT UNDER CONCURRENCY. PSR-6 has no compare-and-swap,
 * and deleteItem() reports success whether or not the key existed, so it
 * cannot be pressed into service as one either. Two simultaneous redemptions
 * of the same handle can both observe isHit() before either deletes, and both
 * would then be handed the same PasskeyChallenge. Deleting before validating
 * narrows that window to the gap between getItem() and deleteItem(); it does
 * not close it. Closing it would mean a lock on every ceremony completion,
 * which is a real per-request cost this deploys nothing to justify — see
 * OAuthStateStore's docblock for the fuller argument, including why the
 * remaining race here is not a security hole: both racers would go on to
 * present the SAME challenge to the same authenticator response, and the
 * WebAuthn signature check downstream can only ever accept one of them.
 */
final readonly class PasskeyChallengeStore
{
    private const int LIFETIME_SECONDS = 300;
    private const string KEY_PREFIX = 'passkey_challenge_';

    public function __construct(
        private CacheItemPoolInterface $passkeyChallengeCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function issue(string $challenge, ?int $userId): string
    {
        $handle = self::randomHandle();

        $item = $this->passkeyChallengeCache->getItem(self::keyFor($handle));
        // The handle itself is absent from the payload for the same reason it
        // is absent from the key: it is the bearer credential, and a readable
        // cache entry must not hand out a usable one.
        $item->set([
            'challenge' => $challenge,
            'user_id' => $userId,
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->passkeyChallengeCache->save($item);

        return $handle;
    }

    /**
     * @throws InvalidArgumentException
     * @throws UnknownChallengeException
     */
    public function consume(string $handle): PasskeyChallenge
    {
        $key = self::keyFor($handle);
        $item = $this->passkeyChallengeCache->getItem($key);

        if (!$item->isHit()) {
            throw new UnknownChallengeException();
        }

        // Deleted before any validation below, so a handle that fails the
        // expiry check is still burned rather than left available to retry.
        $this->passkeyChallengeCache->deleteItem($key);

        $stored = self::decodeStored($item->get());
        if (null === $stored) {
            throw new UnknownChallengeException();
        }

        // The pool's own TTL should have removed this already. This check
        // exists because that TTL is enforced by the cache backend's clock,
        // while the rest of the application — and every test — runs on the
        // injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new UnknownChallengeException();
        }

        return new PasskeyChallenge($stored['challenge'], $stored['user_id']);
    }

    /**
     * Validates the shape of a cache entry written by issue(), returning it
     * typed or null if anything is missing or of the wrong type. A corrupt or
     * tampered entry is thereby treated exactly like an unknown handle.
     *
     * @return array{challenge: string, user_id: ?int, expires_at: int}|null
     */
    private static function decodeStored(mixed $stored): ?array
    {
        if (
            !\is_array($stored)
            || !\is_string($stored['challenge'] ?? null)
            || !\array_key_exists('user_id', $stored)
            || !(null === $stored['user_id'] || \is_int($stored['user_id']))
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            return null;
        }

        return [
            'challenge' => $stored['challenge'],
            'user_id' => $stored['user_id'],
            'expires_at' => $stored['expires_at'],
        ];
    }

    /**
     * The cache key is a digest, not the handle itself, for the reason given
     * in the class docblock. Unsalted SHA-256 is sufficient: the input is 32
     * bytes from random_bytes(), so there is no guessable preimage to protect
     * and no reason to pay a work factor on every ceremony completion.
     */
    private static function keyFor(string $handle): string
    {
        return self::KEY_PREFIX . hash('sha256', $handle);
    }

    /**
     * @throws RandomException
     */
    private static function randomHandle(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
