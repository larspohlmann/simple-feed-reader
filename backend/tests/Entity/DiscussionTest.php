<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;
use PHPUnit\Framework\TestCase;

final class DiscussionTest extends TestCase
{
    public function testNoneCarriesNothing(): void
    {
        $discussion = Discussion::none();

        self::assertNull($discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertNull($discussion->commentsLoad);
    }

    public function testAnAutomaticPageWithoutACommentsFeedKeepsTheLoadMode(): void
    {
        $discussion = Discussion::fromPageAndFeed('https://news.example/item?id=1', null, CommentsLoad::Auto);

        self::assertSame('https://news.example/item?id=1', $discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }

    public function testNothingAtAllIsNone(): void
    {
        self::assertEquals(Discussion::none(), Discussion::fromPageAndFeed(null, null, CommentsLoad::Manual));
    }

    public function testACommentsFeedKeepsItsLoadMode(): void
    {
        $discussion = Discussion::fromPageAndFeed(
            'https://blog.example/post/',
            'https://blog.example/post/feed/',
            CommentsLoad::Auto,
        );

        self::assertSame('https://blog.example/post/', $discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }

    public function testCommentsFeedCarriesItsLoadMode(): void
    {
        $discussion = Discussion::withCommentsFeed(null, 'https://blog.example/post/feed/', CommentsLoad::Manual);

        self::assertNull($discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $discussion->commentsLoad);
    }

    public function testAPlatformThreadLoadsItsCommentsAutomaticallyWithoutAFeed(): void
    {
        $discussion = Discussion::platformThread('https://lemmy.world/post/1');

        self::assertSame('https://lemmy.world/post/1', $discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }

    public function testAPageWithoutAFeedStillHasNoCommentsLoad(): void
    {
        self::assertNull(Discussion::fromPageAndFeed('https://x/p', null, CommentsLoad::Manual)->commentsLoad);
    }

    public function testALoadWithoutAPageOrAFeedIsDropped(): void
    {
        self::assertEquals(Discussion::none(), Discussion::fromPageAndFeed(null, null, CommentsLoad::Auto));
    }

    public function testAFeedKeepsItsLoadWithoutAPage(): void
    {
        $discussion = Discussion::fromPageAndFeed(null, 'https://x/c.xml', CommentsLoad::Auto);

        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }
}
