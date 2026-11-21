<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
use App\Entity\EntryDiscussion;
use App\Enum\CommentsLoad;
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
        $stored->store(Discussion::fromPageAndFeed('https://t.example/1', null, CommentsLoad::Manual));

        self::assertNull($stored->read()->commentsFeedUrl);
        self::assertSame('https://t.example/1', $stored->read()->url);
    }

    public function testAStoredPlatformThreadReadsBackAutomatic(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::platformThread('https://lemmy.world/post/1'));

        $read = $stored->read();

        self::assertSame('https://lemmy.world/post/1', $read->url);
        self::assertNull($read->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $read->commentsLoad);
    }

    public function testEmptyByDefault(): void
    {
        self::assertEquals(Discussion::none(), (new EntryDiscussion())->read());
    }

    public function testAUrlOverTheColumnLimitIsDroppedNotTruncated(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::fromPageAndFeed(str_repeat('a', 2049), null, CommentsLoad::Manual));

        self::assertNull($stored->read()->url);
    }

    public function testAUrlAtTheColumnLimitIsKept(): void
    {
        $url = str_repeat('a', 2048);
        $stored = new EntryDiscussion();
        $stored->store(Discussion::fromPageAndFeed($url, null, CommentsLoad::Manual));

        self::assertSame($url, $stored->read()->url);
    }

    public function testAnOverlongCommentsFeedUrlIsDroppedAndNullsTheLoad(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::withCommentsFeed(
            'https://t.example/1',
            str_repeat('a', 2049),
            CommentsLoad::Manual,
        ));

        $read = $stored->read();

        self::assertNull($read->commentsFeedUrl);
        self::assertNull($read->commentsLoad);
        self::assertSame('https://t.example/1', $read->url);
    }

    public function testACommentsFeedUrlAtTheColumnLimitIsKept(): void
    {
        $commentsFeedUrl = str_repeat('a', 2048);
        $stored = new EntryDiscussion();
        $stored->store(Discussion::withCommentsFeed('https://t.example/1', $commentsFeedUrl, CommentsLoad::Manual));

        $read = $stored->read();

        self::assertSame($commentsFeedUrl, $read->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $read->commentsLoad);
    }

    public function testAnOverlongPlatformThreadAddressLeavesNoAutomaticLoad(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::platformThread(str_repeat('a', 2049)));

        self::assertEquals(Discussion::none(), $stored->read());
    }

    public function testNewCommentCountReplacesTheStoredOneAndUnknownKeepsIt(): void
    {
        $discussion = new EntryDiscussion();

        $discussion->recordCounts(DiscussionCounts::fromScoreAndComments(null, 3));
        $discussion->recordCounts(DiscussionCounts::fromScoreAndComments(null, 5));
        self::assertSame(5, $discussion->getCommentCount());

        $discussion->recordCounts(DiscussionCounts::unknown());
        self::assertSame(5, $discussion->getCommentCount());
    }

    public function testItStoresNoLikeCount(): void
    {
        $discussion = new EntryDiscussion();

        $discussion->recordCounts(DiscussionCounts::fromCommentsAndLikes(1, 7));

        self::assertNull($discussion->getLikeCount());
        self::assertSame(1, $discussion->getCommentCount());
    }
}
