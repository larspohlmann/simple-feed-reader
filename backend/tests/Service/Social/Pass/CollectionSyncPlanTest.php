<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Model\PrivateFeedModel;
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
            ['custom_feed:' . self::ART => 12],
            ['custom_feed:' . self::ART => true],
        );

        self::assertSame(['custom_feed:' . self::ART => 12], $plan->removals());
        self::assertSame([], $plan->additions());
    }

    public function testACompleteEmptyAnswerStillRemoves(): void
    {
        $plan = CollectionSyncPlan::following([], ['list:' . self::NEWS => 3], []);

        self::assertSame(['list:' . self::NEWS => 3], $plan->removals());
    }

    public function testASubscribedCollectionStillOnTheNetworkStays(): void
    {
        $plan = CollectionSyncPlan::following(
            self::network([PrivateFeedModel::customFeed(self::ART, 'Art')]),
            ['custom_feed:' . self::ART => 12],
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
            ['custom_feed:' . self::ART => 4],
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
            ['custom_feed:' . self::ART => 4],
            [],
        );

        self::assertSame(['custom_feed:' . self::ART => 4], $plan->removals());
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
            ['list:' . self::NEWS => 5],
            [],
        );

        self::assertSame([], $plan->additions());
        self::assertEquals([PrivateFeedModel::userList(self::NEWS, 'News')], $plan->toRemember());
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
