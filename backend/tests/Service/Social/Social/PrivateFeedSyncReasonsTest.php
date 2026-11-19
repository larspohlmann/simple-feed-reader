<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Enum\PrivateFeedSyncReason;
use App\Service\Social\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\PrivateFeedSyncReasons;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialRuleWiring;
use PHPUnit\Framework\TestCase;

final class PrivateFeedSyncReasonsTest extends TestCase
{
    private const string OWN_LIST = 'at://did:plc:alice/app.bsky.graph.list/own';
    private const string OTHERS_LIST = 'at://did:plc:bob/app.bsky.graph.list/theirs';
    private const string LOOKALIKE_LIST = 'at://did:plc:alicea/app.bsky.graph.list/theirs';
    private const string CUSTOM_FEED = 'at://did:plc:alice/app.bsky.feed.generator/art';

    public function testANewlySavedCustomFeedWasSaved(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Saved,
            self::reasons()->added(
                SocialAccounts::bluesky(),
                PrivateFeedModel::customFeed(self::CUSTOM_FEED, 'Art'),
            ),
        );
    }

    public function testANewListOfTheAccountsOwnWasCreated(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Created,
            self::reasons()->added(SocialAccounts::bluesky(), PrivateFeedModel::userList(self::OWN_LIST, 'Own')),
        );
    }

    public function testANewListOfSomeoneElseWasSaved(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Saved,
            self::reasons()->added(
                SocialAccounts::bluesky(),
                PrivateFeedModel::userList(self::OTHERS_LIST, 'Theirs'),
            ),
        );
    }

    public function testAListOfADidThatOnlyStartsLikeTheAccountsIsSomeoneElses(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Saved,
            self::reasons()->added(
                SocialAccounts::bluesky(),
                PrivateFeedModel::userList(self::LOOKALIKE_LIST, 'Theirs'),
            ),
        );
    }

    public function testANewMastodonListWasCreated(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Created,
            self::reasons()->added(SocialAccounts::mastodon(), PrivateFeedModel::userList('42', 'Friends')),
        );
    }

    public function testALemmyListIsNeverOwned(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Saved,
            self::reasons()->added(
                SocialAccounts::lemmyOf(SocialAccounts::owner()),
                PrivateFeedModel::userList('1', 'x'),
            ),
        );
    }

    public function testACollectionTheNetworkReportsGoneWasDeleted(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Deleted,
            self::reasons()->removed(
                SocialAccounts::bluesky(),
                PrivateFeedModel::customFeed(self::CUSTOM_FEED, ''),
                new NetworkCollectionsModel([], [], [self::CUSTOM_FEED]),
            ),
        );
    }

    public function testAnAbsentListOfTheAccountsOwnWasDeleted(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Deleted,
            self::reasons()->removed(
                SocialAccounts::bluesky(),
                PrivateFeedModel::userList(self::OWN_LIST, ''),
                self::emptyNetwork(),
            ),
        );
    }

    public function testAnAbsentMastodonListWasDeleted(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Deleted,
            self::reasons()->removed(
                SocialAccounts::mastodon(),
                PrivateFeedModel::userList('42', ''),
                self::emptyNetwork(),
            ),
        );
    }

    public function testAnAbsentCustomFeedTheNetworkStillHasWasUnsaved(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Unsaved,
            self::reasons()->removed(
                SocialAccounts::bluesky(),
                PrivateFeedModel::customFeed(self::CUSTOM_FEED, ''),
                self::emptyNetwork(),
            ),
        );
    }

    public function testAnAbsentListOfSomeoneElseWasUnsaved(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Unsaved,
            self::reasons()->removed(
                SocialAccounts::bluesky(),
                PrivateFeedModel::userList(self::OTHERS_LIST, ''),
                self::emptyNetwork(),
            ),
        );
    }

    public function testANewlyFollowedTagWasFollowed(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Followed,
            self::reasons()->added(
                SocialAccounts::mastodon(),
                PrivateFeedModel::followedTag('caturday', '#caturday'),
            ),
        );
    }

    public function testATagGoneFromTheNetworkWasUnfollowed(): void
    {
        self::assertSame(
            PrivateFeedSyncReason::Unfollowed,
            self::reasons()->removed(
                SocialAccounts::mastodon(),
                PrivateFeedModel::followedTag('caturday', '#caturday'),
                self::emptyNetwork(),
            ),
        );
    }

    private static function reasons(): PrivateFeedSyncReasons
    {
        return new PrivateFeedSyncReasons(SocialRuleWiring::privateFeedRules());
    }

    private static function emptyNetwork(): NetworkCollectionsModel
    {
        return new NetworkCollectionsModel([], []);
    }
}
