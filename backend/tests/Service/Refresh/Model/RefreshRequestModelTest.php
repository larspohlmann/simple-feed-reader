<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Repository\RefreshTarget;
use App\Service\Refresh\Model\RefreshRequestModel;
use PHPUnit\Framework\TestCase;

final class RefreshRequestModelTest extends TestCase
{
    public function testAnAllDueRequestRunsOnScheduleAndPrunes(): void
    {
        $request = RefreshRequestModel::allDue(45);

        self::assertNull($request->userId);
        self::assertNull($request->target->feedId);
        self::assertNull($request->target->tagId);
        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testAUserRequestForcesTheRefreshWithoutPruning(): void
    {
        $request = RefreshRequestModel::forUser(7, RefreshTarget::neverFetchedFeeds(), 30);

        self::assertSame(7, $request->userId);
        self::assertEquals(RefreshTarget::neverFetchedFeeds(), $request->target);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testAFeedRequestHasNoUserAndForcesTheRefreshWithoutPruning(): void
    {
        $request = RefreshRequestModel::forFeed(11, 30);

        self::assertNull($request->userId);
        self::assertEquals(RefreshTarget::feed(11), $request->target);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
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
        $request = RefreshRequestModel::forUser(7, RefreshTarget::tag(13), 30)->withoutPruning();

        self::assertSame(7, $request->userId);
        self::assertEquals(RefreshTarget::tag(13), $request->target);
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
        $request = RefreshRequestModel::forUser(7, RefreshTarget::feed(11), 30)->ignoringSchedule();

        self::assertSame(7, $request->userId);
        self::assertEquals(RefreshTarget::feed(11), $request->target);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testARequestTakesTheDefaultBatchLimit(): void
    {
        self::assertSame(
            RefreshRequestModel::DEFAULT_BATCH_LIMIT,
            RefreshRequestModel::forUser(7, RefreshTarget::everyFeed(), 30)->batchLimit,
        );
    }

    public function testLimitedToChangesOnlyTheBatchLimit(): void
    {
        $request = RefreshRequestModel::forUser(7, RefreshTarget::tag(13), 30)->limitedTo(5);

        self::assertSame(5, $request->batchLimit);
        self::assertSame(7, $request->userId);
        self::assertEquals(RefreshTarget::tag(13), $request->target);
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

    public function testANetworkRequestRefreshesThatNetworksFeedsOfTheUserIgnoringTheSchedule(): void
    {
        $request = RefreshRequestModel::forUser(
            7,
            RefreshTarget::network(NetworkScope::whole(SocialNetwork::Bluesky)),
            30,
        );

        self::assertSame(7, $request->userId);
        self::assertEquals(RefreshTarget::network(NetworkScope::whole(SocialNetwork::Bluesky)), $request->target);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
    }

    public function testDerivedRequestsKeepTheNetwork(): void
    {
        $network = NetworkScope::whole(SocialNetwork::Bluesky);
        $request = RefreshRequestModel::forUser(7, RefreshTarget::network($network), 30);

        self::assertEquals($network, $request->withoutPruning()->target->networkScope);
        self::assertEquals($network, $request->ignoringSchedule()->target->networkScope);
        self::assertEquals($network, $request->limitedTo(5)->target->networkScope);
    }
}
