<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRunProgress;
use PHPUnit\Framework\TestCase;

final class RefreshRunProgressTest extends TestCase
{
    public function testAFreshRunHasDoneNothingAndKnowsNoTotal(): void
    {
        $progress = RefreshRunProgress::start();

        self::assertSame(0, $progress->done);
        self::assertSame(0, $progress->total);
    }

    /**
     * The point of the whole issue. A slice reports its own batch (server-capped
     * at 50) and the run-wide count of what is still due; the run's denominator is
     * neither of those, it is their sum. 20 handled with 180 still due means the
     * run is 20 of 200 — NOT (50 - 180) / 50, which is negative (#721).
     */
    public function testTheFirstSliceEstablishesTheRunWideDenominator(): void
    {
        $progress = RefreshRunProgress::start()->advancedBy(20, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testLaterSlicesAccumulateAgainstThatSameDenominator(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(30, 150);

        self::assertSame(50, $progress->done);
        self::assertSame(200, $progress->total);
    }

    /**
     * Feeds fall due while a long sweep runs. Without the max() the denominator
     * would stay at its first value, `done` would sail past it, and the bar would
     * report more than a full run.
     */
    public function testFeedsFallingDueMidRunGrowTheDenominatorInsteadOfOverfillingIt(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)   // 20 of 200
            ->advancedBy(10, 200);  // 30 done, 200 still due — the run grew

        self::assertSame(30, $progress->done);
        self::assertSame(230, $progress->total);
    }

    /**
     * The denominator is a high-water mark, so a slice that handles work without
     * new feeds arriving must not shrink it — a shrinking total is a bar that
     * jumps forward for no reason.
     */
    public function testTheDenominatorNeverShrinks(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(180, 0);

        self::assertSame(200, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testAFinishedRunIsExactlyFull(): void
    {
        $progress = RefreshRunProgress::start()->advancedBy(8, 0);

        self::assertSame($progress->total, $progress->done);
    }

    /**
     * A slice can legitimately handle nothing — every feed it took on was
     * deferred by the time budget. That must not move the bar, and must not
     * disturb the denominator either.
     */
    public function testASliceThatHandledNothingLeavesTheRunWhereItWas(): void
    {
        $progress = RefreshRunProgress::start()
            ->advancedBy(20, 180)
            ->advancedBy(0, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    /**
     * The store hands a run back to its next slice through this constructor, so
     * it is exercised here rather than left for the store's own tests: a named
     * constructor no test in this class calls is untested code, however soon its
     * caller lands.
     */
    public function testARunResumesExactlyWhereTheStoreLeftIt(): void
    {
        $progress = RefreshRunProgress::resumed(20, 200)->advancedBy(30, 150);

        self::assertSame(50, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testItSerialisesForTheWire(): void
    {
        self::assertSame(
            ['done' => 20, 'total' => 200],
            RefreshRunProgress::start()->advancedBy(20, 180)->toArray(),
        );
    }
}
