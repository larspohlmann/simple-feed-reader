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
        self::assertFalse($discussion->hasCommentsFeed());
    }

    public function testPageHasNoCommentsFeed(): void
    {
        $discussion = Discussion::page('https://news.example/item?id=1');

        self::assertSame('https://news.example/item?id=1', $discussion->url);
        self::assertFalse($discussion->hasCommentsFeed());
    }

    public function testCommentsFeedCarriesItsLoadMode(): void
    {
        $discussion = Discussion::withCommentsFeed(null, 'https://blog.example/post/feed/', CommentsLoad::Manual);

        self::assertNull($discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $discussion->commentsLoad);
        self::assertTrue($discussion->hasCommentsFeed());
    }
}
