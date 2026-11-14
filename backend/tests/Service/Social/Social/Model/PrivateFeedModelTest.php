<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Model\PrivateFeedModel;
use PHPUnit\Framework\TestCase;

final class PrivateFeedModelTest extends TestCase
{
    public function testAKeyNamesTheKindThenTheRemoteId(): void
    {
        self::assertSame('list:at://list/a', PrivateFeedModel::keyOf(SocialFeedKind::UserList, 'at://list/a'));
        self::assertSame('home:', PrivateFeedModel::home()->key());
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
        $trending = PrivateFeedModel::trending();

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
}
