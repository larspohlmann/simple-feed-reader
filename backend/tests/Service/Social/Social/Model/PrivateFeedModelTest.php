<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\PrivateFeedModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateFeedModelTest extends TestCase
{
    public function testAKeyNamesTheKindThenTheRemoteId(): void
    {
        self::assertSame('list:at://list/a', PrivateFeedModel::keyOf(SocialFeedKind::UserList, 'at://list/a'));
        self::assertSame('home:', PrivateFeedModel::timeline(SocialFeedKind::Home)->key());
    }

    public function testAFollowedTagIsKeyedByItsLowercasedNameAndKeepsTheCasingItWasShownIn(): void
    {
        $tag = PrivateFeedModel::followedTag('Caturday', '#Caturday');

        self::assertSame(SocialFeedKind::Tag, $tag->kind);
        self::assertSame('caturday', $tag->remoteId);
        self::assertSame('#Caturday', $tag->name);
        self::assertSame('tag:caturday', $tag->key());
        self::assertEquals($tag, PrivateFeedModel::of(SocialFeedKind::Tag, 'CATURDAY', '#Caturday'));
    }

    public function testAFollowedTagLowercasesBeyondAscii(): void
    {
        $tag = PrivateFeedModel::followedTag('Ärger', '#Ärger');

        self::assertSame('ärger', $tag->remoteId);
        self::assertSame('#Ärger', $tag->name);
    }

    public function testTrendingIsOnePerAccountLikeHome(): void
    {
        $trending = PrivateFeedModel::timeline(SocialFeedKind::Trending);

        self::assertSame(SocialFeedKind::Trending, $trending->kind);
        self::assertSame('', $trending->remoteId);
        self::assertSame('trending:', $trending->key());
        self::assertEquals($trending, PrivateFeedModel::of(SocialFeedKind::Trending, 'ignored', 'ignored'));
    }

    public function testASearchIsNamedAndKeyedByItsNormalisedQuery(): void
    {
        $search = PrivateFeedModel::search('  rss   reader ');

        self::assertSame(SocialFeedKind::Search, $search->kind);
        self::assertSame('rss reader', $search->remoteId);
        self::assertSame('rss reader', $search->name);
        self::assertEquals($search, PrivateFeedModel::of(SocialFeedKind::Search, ' rss reader', 'ignored'));
    }

    public function testEveryTimelineIsNamedAfterItsKindWithoutARemoteId(): void
    {
        $names = [];
        foreach (SocialFeedKind::timelines() as $kind) {
            $timeline = PrivateFeedModel::timeline($kind);
            self::assertSame($kind, $timeline->kind);
            self::assertSame('', $timeline->remoteId);
            self::assertEquals($timeline, PrivateFeedModel::of($kind, 'ignored', 'ignored'));
            $names[] = $timeline->name;
        }

        self::assertSame(['Home', 'Trending', 'Local', 'All'], $names);
    }

    /** @return iterable<string, array{SocialFeedKind}> */
    public static function kindsNamedByTheirRemoteId(): iterable
    {
        yield 'list' => [SocialFeedKind::UserList];
        yield 'custom feed' => [SocialFeedKind::CustomFeed];
        yield 'author' => [SocialFeedKind::Author];
        yield 'tag' => [SocialFeedKind::Tag];
        yield 'search' => [SocialFeedKind::Search];
    }

    #[DataProvider('kindsNamedByTheirRemoteId')]
    public function testAKindNamedByItsRemoteIdIsNoTimeline(SocialFeedKind $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PrivateFeedModel::timeline($kind);
    }
}
