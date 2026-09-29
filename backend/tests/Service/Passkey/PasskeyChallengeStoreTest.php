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
     * At the exact expiry instant the handle is still valid: expiry means strictly in the past. The pool's TTL and the
     * clock check could disagree only here.
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
     * Pins the key's shape, a fixed prefix plus the handle's digest: issue() and consume() would agree on any key, so
     * nothing else would notice a dropped prefix.
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
     * Each row corrupts exactly one field of a well-formed entry, so every field the check reads is proven on its own.
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
     * The stored `expires_at` only guards against clock disagreement; without expiresAfter() a real pool would keep
     * every entry on disk. A mock, since ArrayAdapter has no observable eviction.
     */
    public function testIssueSetsThePoolItemsTtlToTheChallengeLifetime(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(300);

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
