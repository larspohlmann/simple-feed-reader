<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\DeletedPostTombstones;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class DeletedPostTombstonesTest extends TestCase
{
    public function testABuriedPostStaysBuriedInItsFeedForSevenDays(): void
    {
        $clock = new MockClock('2026-11-01 12:00:00');
        $tombstones = new DeletedPostTombstones(new ArrayAdapter(), $clock);

        $tombstones->bury(3, ['hash-a', 'hash-c']);
        $clock->modify('+6 days');
        $tombstones->bury(3, ['hash-b']);

        self::assertSame(['hash-a', 'hash-c', 'hash-b'], $tombstones->buriedIn(3));
        self::assertSame([], $tombstones->buriedIn(4));
        $clock->modify('+1 day');
        self::assertSame(['hash-b'], $tombstones->buriedIn(3));
        $clock->modify('+6 days');
        self::assertSame([], $tombstones->buriedIn(3));
    }

    public function testAStoredEntryWithoutAnExpiryIsNoTombstone(): void
    {
        $clock = new MockClock('2026-11-01 12:00:00');
        $cache = new ArrayAdapter();
        $stored = $cache->getItem('deleted_posts.3');
        $tomorrow = $clock->now()->modify('+1 day')->getTimestamp();
        $cache->save($stored->set(['hash-a' => 'next week', 'hash-b' => $tomorrow]));

        $tombstones = new DeletedPostTombstones($cache, $clock);

        self::assertSame(['hash-b'], $tombstones->buriedIn(3));
    }
}
