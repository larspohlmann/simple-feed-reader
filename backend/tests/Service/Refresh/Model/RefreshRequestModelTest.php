<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Service\Refresh\Model\RefreshRequestModel;
use PHPUnit\Framework\TestCase;

final class RefreshRequestModelTest extends TestCase
{
    public function testAnAllDueRequestRunsOnScheduleAndPrunes(): void
    {
        $request = RefreshRequestModel::allDue(45);

        self::assertNull($request->userId);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testWithoutPruningTurnsOnlyThePruningOff(): void
    {
        $request = RefreshRequestModel::allDue(45)->withoutPruning();

        self::assertFalse($request->prune);
        self::assertFalse($request->force);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testWithoutPruningKeepsTheScope(): void
    {
        $request = RefreshRequestModel::forUserTag(7, 13, 30)->withoutPruning();

        self::assertSame(7, $request->userId);
        self::assertSame(13, $request->tagId);
        self::assertNull($request->feedId);
        self::assertTrue($request->force);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testIgnoringScheduleTurnsOnlyTheScheduleOff(): void
    {
        $request = RefreshRequestModel::allDue(45)->ignoringSchedule();

        self::assertTrue($request->force);
        self::assertTrue($request->prune);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testIgnoringScheduleKeepsTheScope(): void
    {
        $request = RefreshRequestModel::forUserFeed(7, 11, 30)->ignoringSchedule();

        self::assertSame(7, $request->userId);
        self::assertSame(11, $request->feedId);
        self::assertNull($request->tagId);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testARequestTakesTheDefaultBatchLimit(): void
    {
        self::assertSame(
            RefreshRequestModel::DEFAULT_BATCH_LIMIT,
            RefreshRequestModel::forUser(7, 30)->batchLimit,
        );
    }

    public function testLimitedToChangesOnlyTheBatchLimit(): void
    {
        $request = RefreshRequestModel::forUserTag(7, 13, 30)->limitedTo(5);

        self::assertSame(5, $request->batchLimit);
        self::assertSame(7, $request->userId);
        self::assertSame(13, $request->tagId);
        self::assertNull($request->feedId);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testWithoutPruningAndIgnoringScheduleKeepTheBatchLimit(): void
    {
        $request = RefreshRequestModel::allDue(45)->limitedTo(5);

        self::assertSame(5, $request->withoutPruning()->batchLimit);
        self::assertSame(5, $request->ignoringSchedule()->batchLimit);
    }
}
