<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Social\Support\CommentPost;
use PHPUnit\Framework\TestCase;

final class CommentPostTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:ann/app.bsky.feed.post/2';
    private const string LIKE_URI = 'at://did:plc:viewer/app.bsky.feed.like/3';
    private const string STATUS_URI = 'https://mastodon.social/users/ann/statuses/110';
    private const string STATUS_URL = 'https://mastodon.social/@ann/110';

    public function testABlueskyReplyIsItsUriWithItsCounts(): void
    {
        self::assertEquals(
            new CommentPostModel(self::POST_URI, new CommentCountsModel(5, 1, 2, 3), false),
            CommentPost::ofBlueskyPost(self::blueskyPost([])),
        );
    }

    public function testABlueskyReplyTheViewerLikedReadsAsLiked(): void
    {
        self::assertTrue(CommentPost::ofBlueskyPost(self::blueskyPost(['like' => self::LIKE_URI]))?->isLiked);
    }

    public function testAViewerLikeThatIsNoLikeRecordDoesNotCount(): void
    {
        $repost = 'at://did:plc:viewer/app.bsky.feed.repost/3';

        self::assertFalse(CommentPost::ofBlueskyPost(self::blueskyPost(['like' => $repost]))?->isLiked);
    }

    public function testABlueskyReplyWithoutAPostUriIsNoPost(): void
    {
        self::assertNull(CommentPost::ofBlueskyPost(self::blueskyPost([], 'https://bsky.app/profile/ann/post/2')));
    }

    public function testABlueskyReplyWithoutCountsIsNoPost(): void
    {
        self::assertNull(CommentPost::ofBlueskyPost(JsonNodeModel::of(['uri' => self::POST_URI])));
    }

    public function testAMastodonReplyIsItsUriWithItsCountsAndNoQuotes(): void
    {
        self::assertEquals(
            new CommentPostModel(self::STATUS_URI, new CommentCountsModel(7, 2, 1, null), false),
            CommentPost::ofMastodonStatus(self::mastodonStatus(['uri' => self::STATUS_URI, 'url' => self::STATUS_URL])),
        );
    }

    public function testAMastodonReplyWithoutAUriGoesByItsUrl(): void
    {
        $status = self::mastodonStatus(['uri' => 'tag:x', 'url' => self::STATUS_URL]);

        self::assertSame(self::STATUS_URL, CommentPost::ofMastodonStatus($status)?->address);
    }

    public function testAFavouritedMastodonReplyReadsAsLiked(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'favourited' => true]);

        self::assertTrue(CommentPost::ofMastodonStatus($status)?->isLiked);
    }

    public function testAMastodonReplyWithoutAnAddressIsNoPost(): void
    {
        self::assertNull(CommentPost::ofMastodonStatus(self::mastodonStatus([])));
    }

    /** @param array<string, string> $viewer */
    private static function blueskyPost(array $viewer, string $uri = self::POST_URI): JsonNodeModel
    {
        return JsonNodeModel::of([
            'uri' => $uri,
            'likeCount' => 5,
            'repostCount' => 1,
            'replyCount' => 2,
            'quoteCount' => 3,
            'viewer' => $viewer,
        ]);
    }

    /** @param array<string, string|bool> $fields */
    private static function mastodonStatus(array $fields): JsonNodeModel
    {
        return JsonNodeModel::of(['favourites_count' => 7, 'reblogs_count' => 2, 'replies_count' => 1] + $fields);
    }
}
