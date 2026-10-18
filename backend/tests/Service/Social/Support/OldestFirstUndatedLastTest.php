<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\OldestFirstUndatedLast;
use PHPUnit\Framework\TestCase;

final class OldestFirstUndatedLastTest extends TestCase
{
    public function testAnOlderPostComesBeforeANewerOne(): void
    {
        $older = new \DateTimeImmutable('2026-10-10 16:00:00');
        $newer = new \DateTimeImmutable('2026-10-10 16:01:00');

        self::assertSame(-1, OldestFirstUndatedLast::compare($older, $newer));
        self::assertSame(1, OldestFirstUndatedLast::compare($newer, $older));
        self::assertSame(0, OldestFirstUndatedLast::compare($older, $older));
    }

    public function testAnUndatedPostComesAfterADatedOne(): void
    {
        $dated = new \DateTimeImmutable('2026-10-10 16:00:00');

        self::assertSame(1, OldestFirstUndatedLast::compare(null, $dated));
        self::assertSame(-1, OldestFirstUndatedLast::compare($dated, null));
        self::assertSame(0, OldestFirstUndatedLast::compare(null, null));
    }
}
