<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model;

use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NetworkTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\TagTargetModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use PHPUnit\Framework\TestCase;

final class RefreshRequestModelTest extends TestCase
{
    public function testAnAllDueRequestRunsOnScheduleAndPrunes(): void
    {
        $request = RefreshRequestModel::allDue(45);

        self::assertNull($request->userId);
        self::assertEquals(new EveryFeedTargetModel(), $request->target);
        self::assertSame(45, $request->budgetSeconds);
        self::assertTrue($request->prune);
        self::assertFalse($request->force);
    }

    public function testAUserRequestForcesTheRefreshWithoutPruning(): void
    {
        $request = RefreshRequestModel::forUser(7, new NeverFetchedTargetModel(), 30);

        self::assertSame(7, $request->userId);
        self::assertEquals(new NeverFetchedTargetModel(), $request->target);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testAFeedRequestHasNoUserAndForcesTheRefreshWithoutPruning(): void
    {
        $request = RefreshRequestModel::forFeed(11, 30);

        self::assertNull($request->userId);
        self::assertEquals(new FeedTargetModel(11), $request->target);
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
        $request = RefreshRequestModel::forUser(7, new TagTargetModel(13), 30)->withoutPruning();

        self::assertSame(7, $request->userId);
        self::assertEquals(new TagTargetModel(13), $request->target);
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
        $request = RefreshRequestModel::forUser(7, new FeedTargetModel(11), 30)->ignoringSchedule();

        self::assertSame(7, $request->userId);
        self::assertEquals(new FeedTargetModel(11), $request->target);
        self::assertFalse($request->prune);
        self::assertSame(30, $request->budgetSeconds);
    }

    public function testARequestTakesTheDefaultBatchLimit(): void
    {
        self::assertSame(
            RefreshRequestModel::DEFAULT_BATCH_LIMIT,
            RefreshRequestModel::forUser(7, new EveryFeedTargetModel(), 30)->batchLimit,
        );
    }

    public function testLimitedToChangesOnlyTheBatchLimit(): void
    {
        $request = RefreshRequestModel::forUser(7, new TagTargetModel(13), 30)->limitedTo(5);

        self::assertSame(5, $request->batchLimit);
        self::assertSame(7, $request->userId);
        self::assertEquals(new TagTargetModel(13), $request->target);
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
            new NetworkTargetModel(NetworkScope::whole(SocialNetwork::Bluesky)),
            30,
        );

        self::assertSame(7, $request->userId);
        self::assertEquals(new NetworkTargetModel(NetworkScope::whole(SocialNetwork::Bluesky)), $request->target);
        self::assertTrue($request->force);
        self::assertFalse($request->prune);
    }

    public function testDerivedRequestsKeepTheNetwork(): void
    {
        $network = NetworkScope::whole(SocialNetwork::Bluesky);
        $request = RefreshRequestModel::forUser(7, new NetworkTargetModel($network), 30);

        self::assertEquals(new NetworkTargetModel($network), $request->withoutPruning()->target);
        self::assertEquals(new NetworkTargetModel($network), $request->ignoringSchedule()->target);
        self::assertEquals(new NetworkTargetModel($network), $request->limitedTo(5)->target);
    }
}
