<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Service\Refresh\Model\RefreshRunProgressModel;
use PHPUnit\Framework\TestCase;

final class RefreshRunProgressModelTest extends TestCase
{
    public function testAFreshRunHasDoneNothingAndKnowsNoTotal(): void
    {
        $progress = RefreshRunProgressModel::start();

        self::assertSame(0, $progress->done);
        self::assertSame(0, $progress->total);
    }

    /**
     * A slice reports its own batch (capped at 50) and the run-wide count still due; the run's total is their sum, so
     * 20 handled with 180 due is 20 of 200.
     */
    public function testTheFirstSliceEstablishesTheRunWideDenominator(): void
    {
        $progress = RefreshRunProgressModel::start()->advancedBy(20, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testLaterSlicesAccumulateAgainstThatSameDenominator(): void
    {
        $progress = RefreshRunProgressModel::start()
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
        $progress = RefreshRunProgressModel::start()
            ->advancedBy(20, 180)   // 20 of 200
            ->advancedBy(10, 200);  // 30 done, 200 still due — the run grew

        self::assertSame(30, $progress->done);
        self::assertSame(230, $progress->total);
    }

    /**
     * Another sweep can take our due feeds between two slices, so `done + remaining` (75) falls below the total and
     * max() must hold it at 200. Keep numbers like these: when the sum exceeds the total, max() goes untested.
     */
    public function testTheDenominatorHoldsWhenWorkLeavesTheDueSetWithoutBeingHandled(): void
    {
        $progress = RefreshRunProgressModel::start()
            ->advancedBy(20, 180)   // 20 of 200
            ->advancedBy(5, 50);    // 25 done, only 50 left — 130 feeds went elsewhere

        self::assertSame(25, $progress->done);
        self::assertSame(200, $progress->total);
    }

    public function testAFinishedRunIsExactlyFull(): void
    {
        $progress = RefreshRunProgressModel::start()->advancedBy(8, 0);

        self::assertSame($progress->total, $progress->done);
    }

    /**
     * …and the same run reaching its end. Holding the denominator at 200 here
     * would leave a finished run reading 37% and then vanishing.
     */
    public function testAFinishedRunIsFullEvenWhenItsWorkVanishedMidFlight(): void
    {
        $progress = RefreshRunProgressModel::start()
            ->advancedBy(20, 180)
            ->advancedBy(5, 50)
            ->advancedBy(50, 0);

        self::assertSame(75, $progress->done);
        self::assertSame(75, $progress->total);
    }

    /**
     * A slice can legitimately handle nothing — every feed it took on was
     * deferred by the time budget. That must not move the bar, and must not
     * disturb the denominator either.
     */
    public function testASliceThatHandledNothingLeavesTheRunWhereItWas(): void
    {
        $progress = RefreshRunProgressModel::start()
            ->advancedBy(20, 180)
            ->advancedBy(0, 180);

        self::assertSame(20, $progress->done);
        self::assertSame(200, $progress->total);
    }

    /** The store hands a run to its next slice through resumed(), so it is pinned here, not only via the store. */
    public function testARunResumesExactlyWhereTheStoreLeftIt(): void
    {
        $progress = RefreshRunProgressModel::resumed(20, 200)->advancedBy(30, 150);

        self::assertSame(50, $progress->done);
        self::assertSame(200, $progress->total);
    }
}
