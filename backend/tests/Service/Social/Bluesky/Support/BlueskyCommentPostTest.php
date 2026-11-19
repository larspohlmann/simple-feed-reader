<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky\Support;

use App\Service\Content\Comments\Model\CommentCountsModel;
use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\BlueskyCommentPost;
use PHPUnit\Framework\TestCase;

final class BlueskyCommentPostTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:ann/app.bsky.feed.post/2';
    private const string LIKE_URI = 'at://did:plc:viewer/app.bsky.feed.like/3';

    public function testABlueskyReplyIsItsUriWithItsCounts(): void
    {
        self::assertEquals(
            new CommentPostModel(self::POST_URI, new CommentCountsModel(5, 1, 2, 3), self::untouched()),
            BlueskyCommentPost::parsePost(self::blueskyPost([])),
        );
    }

    public function testABlueskyReplyTheViewerLikedReadsAsLiked(): void
    {
        $post = BlueskyCommentPost::parsePost(self::blueskyPost(['like' => self::LIKE_URI]));

        self::assertTrue($post?->viewer->isLiked);
    }

    public function testAViewerLikeThatIsNoLikeRecordDoesNotCount(): void
    {
        $repost = 'at://did:plc:viewer/app.bsky.feed.repost/3';

        self::assertFalse(BlueskyCommentPost::parsePost(self::blueskyPost(['like' => $repost]))?->viewer->isLiked);
    }

    public function testABlueskyReplyTheViewerRepostedReadsAsReposted(): void
    {
        $repost = 'at://did:plc:viewer/app.bsky.feed.repost/3';

        self::assertTrue(BlueskyCommentPost::parsePost(self::blueskyPost(['repost' => $repost]))?->viewer->isReposted);
    }

    public function testAViewerRepostThatIsNoRepostRecordDoesNotCount(): void
    {
        $post = BlueskyCommentPost::parsePost(self::blueskyPost(['repost' => self::LIKE_URI]));

        self::assertFalse($post?->viewer->isReposted);
    }

    public function testABlueskyReplyWithoutAPostUriIsNoPost(): void
    {
        self::assertNull(BlueskyCommentPost::parsePost(self::blueskyPost([], 'https://bsky.app/profile/ann/post/2')));
    }

    public function testABlueskyReplyWithoutCountsIsNoPost(): void
    {
        self::assertNull(BlueskyCommentPost::parsePost(JsonNodeModel::of(['uri' => self::POST_URI])));
    }

    public function testABlueskyReplyNamesItsAuthorsDidAndItsReplyGate(): void
    {
        $post = BlueskyCommentPost::parsePost(JsonNodeModel::of([
            'uri' => self::POST_URI,
            'author' => ['did' => 'did:plc:ann'],
            'viewer' => ['replyDisabled' => true, 'embeddingDisabled' => true],
            'likeCount' => 0,
            'repostCount' => 0,
            'replyCount' => 0,
        ]));

        self::assertNotNull($post);
        self::assertSame('did:plc:ann', $post->authorIdentity);
        self::assertFalse($post->viewer->canReply);
        self::assertFalse($post->viewer->canQuote);
        self::assertFalse($post->viewer->isOwn);
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
}
