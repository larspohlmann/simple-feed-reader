<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SubscribedCollectionModel;
use App\Service\Social\Pass\CollectionSyncPlan;
use PHPUnit\Framework\TestCase;

final class CollectionSyncPlanTest extends TestCase
{
    private const string NEWS = 'at://did:plc:alice/app.bsky.graph.list/news';
    private const string ART = 'at://did:plc:bob/app.bsky.feed.generator/art';
    private const string DROPPED = 'at://did:plc:alice/app.bsky.graph.list/dropped';

    public function testANewCollectionIsAddedAndADroppedOneIsNot(): void
    {
        $plan = CollectionSyncPlan::following(
            self::network([
                PrivateFeedModel::userList(self::NEWS, 'News'),
                PrivateFeedModel::userList(self::DROPPED, 'Dropped'),
            ]),
            [],
            ['list:' . self::DROPPED => true],
        );

        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->additions());
        self::assertSame([], $plan->removals());
        self::assertSame([], $plan->toRemember());
    }

    public function testASubscribedCollectionGoneFromTheNetworkIsRemoved(): void
    {
        $plan = CollectionSyncPlan::following(
            self::network([]),
            self::subscribed(12, PrivateFeedModel::customFeed(self::ART, '')),
            ['custom_feed:' . self::ART => true],
        );

        self::assertEquals([12 => PrivateFeedModel::customFeed(self::ART, '')], $plan->removals());
        self::assertSame([], $plan->additions());
    }

    public function testACompleteEmptyAnswerStillRemoves(): void
    {
        $plan = CollectionSyncPlan::following([], self::subscribed(3, PrivateFeedModel::userList(self::NEWS, '')), []);

        self::assertEquals([3 => PrivateFeedModel::userList(self::NEWS, '')], $plan->removals());
    }

    public function testASubscribedCollectionStillOnTheNetworkStays(): void
    {
        $plan = CollectionSyncPlan::following(
            self::network([PrivateFeedModel::customFeed(self::ART, 'Art')]),
            self::subscribed(12, PrivateFeedModel::customFeed(self::ART, '')),
            ['custom_feed:' . self::ART => true],
        );

        self::assertSame([], $plan->additions());
        self::assertSame([], $plan->removals());
        self::assertSame([], $plan->toRemember());
    }

    public function testABaselineAddsNothingAndRemembersEverythingOnTheNetwork(): void
    {
        $plan = CollectionSyncPlan::baseline(
            self::network([
                PrivateFeedModel::userList(self::NEWS, 'News'),
                PrivateFeedModel::customFeed(self::ART, 'Art'),
            ]),
            self::subscribed(4, PrivateFeedModel::customFeed(self::ART, '')),
            [],
        );

        self::assertSame([], $plan->additions());
        self::assertSame([], $plan->removals());
        self::assertEquals(
            [PrivateFeedModel::userList(self::NEWS, 'News'), PrivateFeedModel::customFeed(self::ART, 'Art')],
            $plan->toRemember(),
        );
    }

    public function testABaselineRemovesWhatIsGoneAndDoesNotRememberIt(): void
    {
        $plan = CollectionSyncPlan::baseline(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News')]),
            self::subscribed(4, PrivateFeedModel::customFeed(self::ART, '')),
            [],
        );

        self::assertEquals([4 => PrivateFeedModel::customFeed(self::ART, '')], $plan->removals());
        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->toRemember());
    }

    public function testABaselineRemembersNothingTwice(): void
    {
        $plan = CollectionSyncPlan::baseline(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News')]),
            [],
            ['list:' . self::NEWS => true],
        );

        self::assertSame([], $plan->toRemember());
    }

    public function testASubscribedCollectionNotYetKnownIsRememberedOutsideABaseline(): void
    {
        $plan = CollectionSyncPlan::following(
            self::network([PrivateFeedModel::userList(self::NEWS, 'News')]),
            self::subscribed(5, PrivateFeedModel::userList(self::NEWS, '')),
            [],
        );

        self::assertSame([], $plan->additions());
        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->toRemember());
    }

    /** @return array<string, SubscribedCollectionModel> */
    private static function subscribed(int $subscriptionId, PrivateFeedModel $collection): array
    {
        return [$collection->key() => new SubscribedCollectionModel($subscriptionId, $collection)];
    }

    /**
     * @param list<PrivateFeedModel> $collections
     *
     * @return array<string, PrivateFeedModel>
     */
    private static function network(array $collections): array
    {
        $byKey = [];
        foreach ($collections as $collection) {
            $byKey[$collection->key()] = $collection;
        }

        return $byKey;
    }
}
