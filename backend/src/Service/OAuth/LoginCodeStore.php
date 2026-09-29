<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Service\Auth\Exception\InvalidTokenException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Hands the callback's user to the SPA as a 30-second, single-use code bound to the flow cookie, so no JWT rides in a
 * redirect. A wrong binding must look exactly like an unknown code. The accepted redemption race:
 * docs/oauth-sign-in.md#storage-and-the-accepted-race
 */
final readonly class LoginCodeStore
{
    public const int LIFETIME_SECONDS = 30;
    private const string KEY_PREFIX = 'oauth_login_code_';

    public function __construct(
        private CacheItemPoolInterface $loginCodeCache,
        private ClockInterface $clock,
    ) {
    }

    /**
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
     * @throws InvalidTokenException when the code is unknown, spent, expired, or presented by another browser
     * @throws InvalidArgumentException
     */
    public function consume(string $code, ?string $browserToken): int
    {
        $key = self::keyFor($code);
        $item = $this->loginCodeCache->getItem($key);

        if (!$item->isHit()) {
            throw new InvalidTokenException();
        }

        // Deleted before the checks below, so a failed check burns the code instead of leaving it to retry.
        $this->loginCodeCache->deleteItem($key);

        $stored = $item->get();
        if (
            !\is_array($stored)
            || !\is_int($stored['user_id'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            throw new InvalidTokenException();
        }

        // hash_equals: the stored digest is secret-derived, and a byte-wise compare leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            throw new InvalidTokenException();
        }

        // The pool's TTL runs on the cache backend's clock; this runs on the injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new InvalidTokenException();
        }

        return $stored['user_id'];
    }

    private static function keyFor(string $code): string
    {
        return self::KEY_PREFIX . self::digest($code);
    }

    /**
     * Unsalted SHA-256 is enough: every input is 32 random bytes. docs/oauth-sign-in.md#storage-and-the-accepted-race
     */
    private static function digest(string $value): string
    {
        return hash('sha256', $value);
    }
}
