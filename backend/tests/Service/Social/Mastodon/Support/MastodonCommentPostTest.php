<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Content\Comments\Model\CommentCountsModel;
use App\Service\Content\Comments\Model\CommentPostModel;
use App\Service\Content\Comments\Model\CommentViewerModel;
use App\Service\Social\Mastodon\Support\MastodonCommentPost;
use PHPUnit\Framework\TestCase;

final class MastodonCommentPostTest extends TestCase
{
    private const string STATUS_URI = 'https://mastodon.social/users/ann/statuses/110';
    private const string STATUS_URL = 'https://mastodon.social/@ann/110';

    public function testAMastodonReplyIsItsUriWithItsCountsAndNoQuotes(): void
    {
        self::assertEquals(
            new CommentPostModel(
                self::STATUS_URI,
                new CommentCountsModel(7, 2, 1, null),
                new CommentViewerModel(false, false, true, canQuote: false),
            ),
            MastodonCommentPost::parseStatus(
                self::mastodonStatus(['uri' => self::STATUS_URI, 'url' => self::STATUS_URL]),
            ),
        );
    }

    public function testAMastodonReplyWithoutAUriGoesByItsUrl(): void
    {
        $status = self::mastodonStatus(['uri' => 'tag:x', 'url' => self::STATUS_URL]);

        self::assertSame(self::STATUS_URL, MastodonCommentPost::parseStatus($status)?->address);
    }

    public function testAFavouritedMastodonReplyReadsAsLiked(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'favourited' => true]);

        self::assertTrue(MastodonCommentPost::parseStatus($status)?->viewer->isLiked);
    }

    public function testABoostedMastodonReplyReadsAsReposted(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'reblogged' => true]);

        self::assertTrue(MastodonCommentPost::parseStatus($status)?->viewer->isReposted);
    }

    public function testAPrivateMastodonReplyCannotBeBoosted(): void
    {
        $status = self::mastodonStatus(['uri' => self::STATUS_URI, 'visibility' => 'private']);

        self::assertFalse(MastodonCommentPost::parseStatus($status)?->viewer->canRepost);
    }

    public function testAMastodonReplyWithoutAnAddressIsNoPost(): void
    {
        self::assertNull(MastodonCommentPost::parseStatus(self::mastodonStatus([])));
    }

    public function testAMastodonReplyNamesItsAuthorAsAnIdentityAndTakesReplies(): void
    {
        $status = self::mastodonStatus([
            'uri' => self::STATUS_URI,
            'account' => ['acct' => 'Ann', 'url' => 'https://mastodon.social/@Ann'],
            'quote_approval' => ['current_user' => 'manual'],
        ]);

        $post = MastodonCommentPost::parseStatus($status);

        self::assertNotNull($post);
        self::assertSame('ann@mastodon.social', $post->authorIdentity);
        self::assertTrue($post->viewer->canReply);
        self::assertTrue($post->viewer->canQuote);
    }

    /** @param array<string, string|bool|array<string, string>> $fields */
    private static function mastodonStatus(array $fields): JsonNodeModel
    {
        return JsonNodeModel::of(['favourites_count' => 7, 'reblogs_count' => 2, 'replies_count' => 1] + $fields);
    }
}
