<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Service\Passkey\Exception\UnknownChallengeException;
use App\Service\Passkey\PasskeyChallengeStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PasskeyChallengeStoreTest extends TestCase
{
    public function testAHandleIsRedeemedExactlyOnce(): void
    {
        $store = $this->store();
        $handle = $store->issue('a-challenge', userId: 7);

        $record = $store->consume($handle);
        self::assertSame('a-challenge', $record->challenge);
        self::assertSame(7, $record->userId);

        $this->expectException(UnknownChallengeException::class);
        $store->consume($handle);
    }

    public function testAnExpiredHandleIsRefused(): void
    {
        $clock = new MockClock('2026-08-29 10:00:00');
        $store = $this->store($clock);
        $handle = $store->issue('a-challenge', userId: null);

        $clock->modify('+6 minutes');

        $this->expectException(UnknownChallengeException::class);
        $store->consume($handle);
    }

    /**
     * The exact instant a challenge's own `expires_at` field equals the
     * clock's `now()` must still be VALID — expiry is "strictly in the
     * past", not "at or before now". This is the one instant the pool's own
     * TTL and this method's own clock-based check could disagree about, so
     * it is the only instant worth pinning exactly.
     */
    public function testAHandleIsStillValidAtTheExactExpiryInstant(): void
    {
        $clock = new MockClock('2026-08-29 10:00:00');
        $store = $this->store($clock);
        $handle = $store->issue('a-challenge', userId: null);

        // issue()'s own LIFETIME_SECONDS is 5 minutes; this lands exactly on
        // the stored expires_at, not a moment before or after it.
        $clock->modify('+5 minutes');

        $record = $store->consume($handle);
        self::assertSame('a-challenge', $record->challenge);
    }

    public function testAnUnknownHandleIsRefused(): void
    {
        $this->expectException(UnknownChallengeException::class);
        $this->store()->consume('never-issued');
    }

    /**
     * For the five minutes a handle is live it is a bearer credential, so a
     * readable cache directory must not be a list of usable ones. Same
     * reasoning as OAuthStateStore.
     */
    public function testTheHandleItselfIsNotTheCacheKey(): void
    {
        $pool = new ArrayAdapter();
        $handle = $this->store(pool: $pool)->issue('a-challenge', userId: null);

        self::assertFalse($pool->hasItem($handle));
    }

    /**
     * Pins the key's actual shape — a fixed, readable prefix followed by the
     * handle's digest — rather than only proving the raw handle is absent
     * (testTheHandleItselfIsNotTheCacheKey above). Without this, a change
     * that silently drops or reorders the prefix would pass every other test
     * in this file, since issue() and consume() would still agree with each
     * other on whatever key they compute.
     */
    public function testTheCacheKeyIsThePrefixedDigestOfTheHandle(): void
    {
        $pool = new ArrayAdapter();
        $handle = $this->store(pool: $pool)->issue('a-challenge', userId: null);

        self::assertTrue($pool->hasItem('passkey_challenge_' . hash('sha256', $handle)));
    }

    public function testTheIssuedHandleHasTheExpectedLength(): void
    {
        $handle = $this->store()->issue('a-challenge', userId: null);

        // base64url, unpadded, of the 32 random bytes issue() mints.
        self::assertSame(43, \strlen($handle));
    }

    /**
     * Each row below corrupts exactly ONE field of an otherwise well-formed
     * stored entry, so every field the well-formedness check reads is proven
     * load-bearing on its own, not just as part of the whole chain passing or
     * failing together.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedEntryProvider(): iterable
    {
        $wellFormed = [
            'challenge' => 'a-challenge',
            'user_id' => 7,
            'user_handle' => 'a-handle',
            'expires_at' => 9_999_999_999,
        ];

        yield 'challenge is not a string' => [[...$wellFormed, 'challenge' => 42]];
        yield 'user_id is neither null nor an int' => [[...$wellFormed, 'user_id' => 'seven']];
        yield 'user_handle is neither null nor a string' => [[...$wellFormed, 'user_handle' => 42]];
        yield 'expires_at is missing' => [
            ['challenge' => 'a-challenge', 'user_id' => 7, 'user_handle' => 'a-handle'],
        ];
        yield 'user_id key is missing entirely' => [
            ['challenge' => 'a-challenge', 'user_handle' => 'a-handle', 'expires_at' => 9_999_999_999],
        ];
        yield 'user_handle key is missing entirely' => [
            ['challenge' => 'a-challenge', 'user_id' => 7, 'expires_at' => 9_999_999_999],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    #[DataProvider('malformedEntryProvider')]
    public function testAMalformedStoredEntryIsRefusedLikeAnUnknownHandle(array $stored): void
    {
        $pool = new ArrayAdapter();
        $handle = $this->store(pool: $pool)->issue('a-challenge', userId: null);
        $item = $pool->getItem('passkey_challenge_' . hash('sha256', $handle));
        $item->set($stored);
        $pool->save($item);

        $this->expectException(UnknownChallengeException::class);
        $this->store(pool: $pool)->consume($handle);
    }

    /**
     * The stored 'expires_at' field only guards against the injected clock
     * disagreeing with the pool's own — see consume()'s docblock — so it is
     * NOT a substitute for the pool's own TTL: without expiresAfter(), a
     * real PSR-6 backend would keep a "consumed by our own check" entry on
     * disk forever, growing the cache directory the whole class docblock
     * calls out as sensitive. Verified with a mock rather than ArrayAdapter,
     * which has no observable eviction to assert on.
     */
    public function testIssueSetsThePoolItemsTtlToTheChallengeLifetime(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(300);

        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        (new PasskeyChallengeStore($pool, new MockClock('2026-08-29 10:00:00')))
            ->issue('a-challenge', userId: null);
    }

    private function store(?ClockInterface $clock = null, ?CacheItemPoolInterface $pool = null): PasskeyChallengeStore
    {
        return new PasskeyChallengeStore(
            $pool ?? new ArrayAdapter(storeSerialized: false),
            $clock ?? new MockClock('2026-08-29 10:00:00'),
        );
    }
}
