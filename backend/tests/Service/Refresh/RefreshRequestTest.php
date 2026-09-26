<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRequest;
use PHPUnit\Framework\TestCase;

final class RefreshRequestTest extends TestCase
{
    public function testAnAllDueRequestRunsOnScheduleAndPrunes(): void
    {
        $request = RefreshRequest::allDue(45);

        self::assertNull($request->userId);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testWithoutPruningTurnsOnlyThePruningOff(): void
    {
        $request = RefreshRequest::allDue(45)->withoutPruning();

        self::assertFalse($request->prune);
        self::assertFalse($request->force);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testWithoutPruningKeepsTheScope(): void
    {
        $request = RefreshRequest::forUserTag(7, 13, 30)->withoutPruning();

        self::assertSame(7, $request->userId);
        self::assertSame(13, $request->tagId);
        self::assertNull($request->feedId);
        self::assertTrue($request->force);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testIgnoringScheduleTurnsOnlyTheScheduleOff(): void
    {
        $request = RefreshRequest::allDue(45)->ignoringSchedule();

        self::assertTrue($request->force);
        self::assertTrue($request->prune);
        self::assertSame(45, $request->budgetSeconds);
    }

    public function testIgnoringScheduleKeepsTheScope(): void
    {
        $request = RefreshRequest::forUserFeed(7, 11, 30)->ignoringSchedule();

        self::assertSame(7, $request->userId);
        self::assertSame(11, $request->feedId);
        self::assertNull($request->tagId);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }
}
