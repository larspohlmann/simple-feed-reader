<?php

declare(strict_types=1);

namespace App\Tests\Service\Discussion;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
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

    public function testAPageWithoutACommentsFeedDropsTheLoadMode(): void
    {
        $discussion = Discussion::of('https://news.example/item?id=1', null, CommentsLoad::Auto);

        self::assertSame('https://news.example/item?id=1', $discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertNull($discussion->commentsLoad);
    }

    public function testNothingAtAllIsNone(): void
    {
        self::assertEquals(Discussion::none(), Discussion::of(null, null, CommentsLoad::Manual));
    }

    public function testACommentsFeedKeepsItsLoadMode(): void
    {
        $discussion = Discussion::of(
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
}
