<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryDiscussion;
use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use PHPUnit\Framework\TestCase;

final class EntryDiscussionTest extends TestCase
{
    public function testRoundTripsACommentsFeed(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::withCommentsFeed(
            'https://t.example/1',
            'https://t.example/1/.rss',
            CommentsLoad::Auto,
        ));

        $read = $stored->read();

        self::assertSame('https://t.example/1', $read->url);
        self::assertSame('https://t.example/1/.rss', $read->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $read->commentsLoad);
    }

    public function testRoundTripsAPageOnly(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::page('https://t.example/1'));

        self::assertFalse($stored->read()->hasCommentsFeed());
        self::assertSame('https://t.example/1', $stored->read()->url);
    }

    public function testEmptyByDefault(): void
    {
        self::assertEquals(Discussion::none(), (new EntryDiscussion())->read());
    }
}
