<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentViewerModel;
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
            new CommentPostModel(self::POST_URI, new CommentCountsModel(5, 1, 2, 3), self::untouched()),
            CommentPost::ofBlueskyPost(self::blueskyPost([])),
        );
    }

    public function testABlueskyReplyTheViewerLikedReadsAsLiked(): void
    {
        self::assertTrue(CommentPost::ofBlueskyPost(self::blueskyPost(['like' => self::LIKE_URI]))?->viewer->isLiked);
    }

    public function testAViewerLikeThatIsNoLikeRecordDoesNotCount(): void
    {
        $repost = 'at://did:plc:viewer/app.bsky.feed.repost/3';

        self::assertFalse(CommentPost::ofBlueskyPost(self::blueskyPost(['like' => $repost]))?->viewer->isLiked);
    }

    public function testABlueskyReplyTheViewerRepostedReadsAsReposted(): void
    {
        $repost = 'at://did:plc:viewer/app.bsky.feed.repost/3';

        self::assertTrue(CommentPost::ofBlueskyPost(self::blueskyPost(['repost' => $repost]))?->viewer->isReposted);
    }

    public function testAViewerRepostThatIsNoRepostRecordDoesNotCount(): void
    {
        $post = CommentPost::ofBlueskyPost(self::blueskyPost(['repost' => self::LIKE_URI]));

        self::assertFalse($post?->viewer->isReposted);
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
            new CommentPostModel(self::STATUS_URI, new CommentCountsModel(7, 2, 1, null), self::untouched()),
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

        self::assertTrue(CommentPost::ofMastodonStatus($status)?->viewer->isLiked);
    }

    public function testABoostedMastodonReplyReadsAsReposted(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'reblogged' => true]);

        self::assertTrue(CommentPost::ofMastodonStatus($status)?->viewer->isReposted);
    }

    public function testAPrivateMastodonReplyCannotBeBoosted(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'visibility' => 'private']);

        self::assertFalse(CommentPost::ofMastodonStatus($status)?->viewer->canRepost);
    }

    public function testAMastodonReplyWithoutAnAddressIsNoPost(): void
    {
        self::assertNull(CommentPost::ofMastodonStatus(self::mastodonStatus([])));
    }

    public function testABlueskyReplyNamesItsAuthorsDidAndItsReplyGate(): void
    {
        $post = CommentPost::ofBlueskyPost(JsonNodeModel::of([
            'uri' => self::POST_URI,
            'author' => ['did' => 'did:plc:ann'],
            'viewer' => ['replyDisabled' => true],
            'likeCount' => 0,
            'repostCount' => 0,
            'replyCount' => 0,
        ]));

        self::assertNotNull($post);
        self::assertSame('did:plc:ann', $post->authorIdentity);
        self::assertFalse($post->viewer->canReply);
        self::assertFalse($post->viewer->isOwn);
    }

    public function testAMastodonReplyNamesItsAuthorAsAnIdentityAndTakesReplies(): void
    {
        $status = self::mastodonStatus([
            'uri' => self::STATUS_URI,
            'account' => ['acct' => 'Ann', 'url' => 'https://mastodon.social/@Ann'],
        ]);

        $post = CommentPost::ofMastodonStatus($status);

        self::assertNotNull($post);
        self::assertSame('ann@mastodon.social', $post->authorIdentity);
        self::assertTrue($post->viewer->canReply);
    }

    private static function untouched(): CommentViewerModel
    {
        return new CommentViewerModel(false, false, true);
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

    /** @param array<string, string|bool|array<string, string>> $fields */
    private static function mastodonStatus(array $fields): JsonNodeModel
    {
        return JsonNodeModel::of(['favourites_count' => 7, 'reblogs_count' => 2, 'replies_count' => 1] + $fields);
    }
}
