<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunStore;
use App\Service\Refresh\TrackedRefreshRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TrackedRefreshRunnerTest extends TestCase
{
    private const int BUDGET = 25;

    private RefreshRunStore $store;

    protected function setUp(): void
    {
        $this->store = new RefreshRunStore(new ArrayAdapter());
    }

    /**
     * A 200-feed sweep's first slice takes a batch of 50, finishes 20 and leaves 180 due: the run is 20 of 200, not
     * (50 - 180) / 50.
     */
    public function testTheFirstSliceOfALargeSweepReportsRunWideProgress(): void
    {
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(50, 20, 0, 0, 0, 30, 180, 0),
        );

        $tracked = $runner->run(RefreshRequestModel::forUser(1, self::BUDGET));

        self::assertSame('partial', $tracked->report->status);
        self::assertSame(20, $tracked->progress->done);
        self::assertSame(200, $tracked->progress->total);
    }

    public function testProgressCarriesAcrossSlicesAndOnlyEverMovesForward(): void
    {
        $request = RefreshRequestModel::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(50, 20, 0, 0, 0, 30, 180, 0),
            RefreshReportModel::finished(50, 45, 5, 0, 0, 0, 130, 0),
            RefreshReportModel::finished(50, 48, 2, 0, 0, 0, 80, 0),
        );

        $first = $runner->run($request)->progress;
        $second = $runner->run($request)->progress;
        $third = $runner->run($request)->progress;

        self::assertSame([20, 200], [$first->done, $first->total]);
        self::assertSame([70, 200], [$second->done, $second->total]);
        self::assertSame([120, 200], [$third->done, $third->total]);
    }

    /** Every outcome that ends a feed's turn counts, not only a successful fetch. */
    public function testNotModifiedFailedAndThrottledFeedsAllCountAsHandled(): void
    {
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(8, 2, 3, 2, 1, 0, 0, 0),
        );

        $tracked = $runner->run(RefreshRequestModel::forUser(1, self::BUDGET));

        self::assertSame(8, $tracked->progress->done);
        self::assertSame(8, $tracked->progress->total);
    }

    /**
     * A busy answer means the global lock was held and NO slice ran. Its tally is
     * all zeros, including `remaining` — folding that in would set the denominator
     * to whatever was already done and slam the bar to full.
     */
    public function testABusyAnswerLeavesTheRunExactlyWhereItWas(): void
    {
        $request = RefreshRequestModel::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(50, 20, 0, 0, 0, 30, 180, 0),
            RefreshReportModel::busy(),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame('busy', $tracked->report->status);
        self::assertSame(20, $tracked->progress->done);
        self::assertSame(200, $tracked->progress->total);
    }

    /** A finished run must not be resumed by the next press of Refresh. */
    public function testAFinishedRunIsForgotten(): void
    {
        $request = RefreshRequestModel::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(4, 4, 0, 0, 0, 0, 0, 0),
            RefreshReportModel::finished(2, 2, 0, 0, 0, 0, 0, 0),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(2, $tracked->progress->done);
        self::assertSame(2, $tracked->progress->total);
    }

    /**
     * Unlike the runs above, the first slice here saves (20, 200), so a forget() that did nothing would leave the next
     * press resuming a stale run.
     */
    public function testCompletingARunClearsTheDenominatorASavedEarlierSliceLeftBehind(): void
    {
        $request = RefreshRequestModel::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReportModel::finished(50, 20, 0, 0, 0, 30, 180, 0),
            RefreshReportModel::finished(50, 20, 0, 0, 0, 0, 0, 0),
            RefreshReportModel::finished(3, 3, 0, 0, 0, 0, 0, 0),
        );

        $runner->run($request);
        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(3, $tracked->progress->done);
        self::assertSame(3, $tracked->progress->total);
    }

    /**
     * An aborted run is over: the EntityManager is closed and the client stops.
     * Leaving its record behind would have the next run resume a dead one.
     */
    public function testAnAbortedRunIsForgotten(): void
    {
        $request = RefreshRequestModel::forUser(1, self::BUDGET);
        $runner = $this->trackedRunner(
            RefreshReportModel::aborted(50, 3, 0, 0, 0, 47),
            RefreshReportModel::finished(2, 2, 0, 0, 0, 0, 0, 0),
        );

        $runner->run($request);
        $tracked = $runner->run($request);

        self::assertSame(2, $tracked->progress->done);
        self::assertSame(2, $tracked->progress->total);
    }

    private function trackedRunner(RefreshReportModel ...$reports): TrackedRefreshRunner
    {
        return new TrackedRefreshRunner(new FakeRefreshRunner(...$reports), $this->store);
    }
}
