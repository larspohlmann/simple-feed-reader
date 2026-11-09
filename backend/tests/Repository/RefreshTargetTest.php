<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Repository\RefreshTarget;
use PHPUnit\Framework\TestCase;

final class RefreshTargetTest extends TestCase
{
    public function testEveryFeedNarrowsNothing(): void
    {
        $target = RefreshTarget::everyFeed();

        self::assertNull($target->feedId);
        self::assertNull($target->tagId);
        self::assertNull($target->networkScope);
        self::assertNull($target->feedGroup);
        self::assertFalse($target->neverFetched);
        self::assertSame('all', $target->runKey());
    }

    public function testEachTargetNamesItsOwnRun(): void
    {
        self::assertSame('feed-7', RefreshTarget::feed(7)->runKey());
        self::assertSame('tag-7', RefreshTarget::tag(7)->runKey());
        self::assertSame('group-tags', RefreshTarget::feedGroup(FeedGroup::Tags)->runKey());
        self::assertSame('never-fetched', RefreshTarget::neverFetchedFeeds()->runKey());
        $networkScope = NetworkScope::whole(SocialNetwork::Mastodon);
        self::assertSame(
            'set-network-' . $networkScope->toRunKey(),
            RefreshTarget::network($networkScope)->runKey(),
        );
    }

    public function testAFactorySetsOnlyItsOwnField(): void
    {
        $target = RefreshTarget::tag(3);

        self::assertSame(3, $target->tagId);
        self::assertNull($target->feedId);
        self::assertTrue(RefreshTarget::neverFetchedFeeds()->neverFetched);
        self::assertNull(RefreshTarget::neverFetchedFeeds()->tagId);
    }
}
