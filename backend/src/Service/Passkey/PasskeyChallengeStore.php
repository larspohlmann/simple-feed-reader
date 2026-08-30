<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\UnknownChallengeException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Holds a WebAuthn ceremony's challenge between the moment the options are
 * handed to the browser and the moment its response comes back. Server-side,
 * because the challenge must not be guessable and the API keeps no session.
 *
 * Modelled on OAuthStateStore: the handle issue() returns is a bearer
 * credential for its five-minute lifetime, so only its digest is used as the
 * cache key — a readable cache directory on shared hosting must not double
 * as a list of usable handles — and consume() deletes the entry before
 * validating it, so a handle that fails the expiry check is burned rather
 * than left available to retry.
 *
 * $userHandle rides along with a registration challenge because
 * PasskeyCredentials::userHandleFor() mints a fresh random value for an
 * account's first credential on every call. The value shown to the browser
 * at options time is the one an authenticator remembers and returns at
 * login, so verification must reuse that exact value rather than minting a
 * new one. It is null for a login ceremony, same as $userId.
 *
 * Single use is best-effort under concurrency: PSR-6 has no
 * compare-and-swap, so two simultaneous redemptions of the same handle can
 * both observe isHit() before either deletes. Deleting before validating
 * narrows that window but does not close it — closing it fully would mean a
 * lock on every ceremony completion. See OAuthStateStore's docblock for why
 * the remaining race is not a security hole: both racers present the same
 * challenge, and only one can pass the signature check downstream.
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
    public function issue(string $challenge, ?int $userId, ?string $userHandle = null): string
    {
        $handle = self::randomHandle();

        $item = $this->passkeyChallengeCache->getItem(self::keyFor($handle));
        // The handle itself is absent from the payload for the same reason it
        // is absent from the key: it is the bearer credential, and a readable
        // cache entry must not hand out a usable one.
        $item->set([
            'challenge' => $challenge,
            'user_id' => $userId,
            'user_handle' => $userHandle,
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

        $stored = $item->get();
        if (!self::isWellFormed($stored)) {
            throw new UnknownChallengeException();
        }

        // The pool's own TTL should have removed this already. This check
        // exists because that TTL is enforced by the cache backend's clock,
        // while the rest of the application — and every test — runs on the
        // injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new UnknownChallengeException();
        }

        return new PasskeyChallenge($stored['challenge'], $stored['user_id'], $stored['user_handle']);
    }

    /**
     * Validates the shape of a cache entry written by issue(). A corrupt or
     * tampered entry is thereby treated exactly like an unknown handle.
     *
     * @phpstan-assert-if-true array{challenge: string, user_id: ?int, user_handle: ?string, expires_at: int} $stored
     */
    private static function isWellFormed(mixed $stored): bool
    {
        return \is_array($stored)
            && \is_string($stored['challenge'] ?? null)
            && \array_key_exists('user_id', $stored)
            && self::isNullableInt($stored['user_id'])
            && \array_key_exists('user_handle', $stored)
            && self::isNullableString($stored['user_handle'])
            && \is_int($stored['expires_at'] ?? null);
    }

    private static function isNullableInt(mixed $value): bool
    {
        return null === $value || \is_int($value);
    }

    private static function isNullableString(mixed $value): bool
    {
        return null === $value || \is_string($value);
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
        return Base64UrlSafe::encodeUnpadded(random_bytes(32));
    }
}
