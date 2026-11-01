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
}
