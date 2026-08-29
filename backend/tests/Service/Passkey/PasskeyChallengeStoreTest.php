<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Service\Passkey\Exception\UnknownChallengeException;
use App\Service\Passkey\PasskeyChallengeStore;
use PHPUnit\Framework\TestCase;
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

    private function store(?ClockInterface $clock = null, ?CacheItemPoolInterface $pool = null): PasskeyChallengeStore
    {
        return new PasskeyChallengeStore(
            $pool ?? new ArrayAdapter(storeSerialized: false),
            $clock ?? new MockClock('2026-08-29 10:00:00'),
        );
    }
}
