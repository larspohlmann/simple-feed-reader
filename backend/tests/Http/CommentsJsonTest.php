<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\EntryMedia;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Http\QuotedPostJson;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Tests\Support\JsonMappers;
use PHPUnit\Framework\TestCase;

final class CommentsJsonTest extends TestCase
{
    public function testAnOkResultCarriesEveryCommentFieldTheOmittedCountAndTheFetchTime(): void
    {
        $quote = new QuotedPostContent(
            'https://bsky.app/profile/b/post/1',
            'Bea',
            'b.test',
            null,
            null,
            'quoted',
            [],
            null,
        );
        $card = new LinkCardContent('https://site.test/a', 'A page', null, null, 'site.test');
        $medium = new EntryMedium('https://cdn.test/v.m3u8', 'video', 16, 9, 'https://cdn.test/v.jpg');
        $comment = new EntryCommentModel(
            new CommentAuthorModel('Ann', '@ann.test', 'https://bsky.app/profile/ann.test', 'https://cdn.test/a.jpg'),
            'https://bsky.app/profile/ann.test/post/2',
            new \DateTimeImmutable('2026-10-16T10:00:00+00:00'),
            '<p>Hi</p>',
            false,
            new CommentEmbedsModel([$medium], $card, $quote),
            2,
            new CommentPostModel('at://did:plc:ann/app.bsky.feed.post/2', new CommentCountsModel(5, 1, 2, 0), true),
        );

        $json = JsonMappers::commentsJson()->one(CommentsResultModel::ok(
            new CommentsPageModel([$comment], 7),
            new \DateTimeImmutable('2026-10-16T11:00:00+00:00'),
        ));

        self::assertSame('ok', $json['status']);
        self::assertSame(7, $json['omitted']);
        self::assertSame('2026-10-16T11:00:00+00:00', $json['fetchedAt']);
        self::assertSame([
            'author' => 'Ann',
            'authorHandle' => '@ann.test',
            'authorUrl' => 'https://bsky.app/profile/ann.test',
            'authorAvatarUrl' => 'https://cdn.test/a.jpg',
            'url' => 'https://bsky.app/profile/ann.test/post/2',
            'publishedAt' => '2026-10-16T10:00:00+00:00',
            'html' => '<p>Hi</p>',
            'byEntryAuthor' => false,
            'depth' => 2,
            'media' => EntryMedia::toJsonList([$medium]),
            'linkCard' => [
                'url' => 'https://site.test/a',
                'title' => 'A page',
                'description' => null,
                'imageUrl' => null,
                'siteName' => 'site.test',
                'readerToken' => JsonMappers::readerToken('https://site.test/a'),
            ],
            'quote' => QuotedPostJson::ofOrNull($quote),
            'post' => [
                'address' => 'at://did:plc:ann/app.bsky.feed.post/2',
                'counts' => ['likes' => 5, 'reposts' => 1, 'replies' => 2, 'quotes' => 0],
                'isLiked' => true,
            ],
        ], self::firstComment($json));
    }

    public function testACommentThatIsNoNetworkPostHasNoPost(): void
    {
        $comment = new EntryCommentModel(new CommentAuthorModel(null), null, null, '', false);

        $json = self::firstComment(JsonMappers::commentsJson()->one(CommentsResultModel::ok(
            new CommentsPageModel([$comment]),
            new \DateTimeImmutable('2026-10-16T11:00:00+00:00'),
        )));

        self::assertNull($json['post']);
    }

    public function testACommentWithoutEmbedsHasNoCardNoQuoteAndNoMedia(): void
    {
        $comment = new EntryCommentModel(new CommentAuthorModel(null), null, null, '', false);

        $json = self::firstComment(JsonMappers::commentsJson()->one(CommentsResultModel::ok(
            new CommentsPageModel([$comment]),
            new \DateTimeImmutable('2026-10-16T11:00:00+00:00'),
        )));

        self::assertSame([], $json['media']);
        self::assertNull($json['linkCard']);
        self::assertNull($json['quote']);
    }

    public function testAThrottledResultSaysWhenToAskAgain(): void
    {
        self::assertSame(
            ['status' => 'throttled', 'retryAfter' => 30],
            JsonMappers::commentsJson()->one(CommentsResultModel::throttled(30)),
        );
    }

    public function testAFailedResultCarriesOnlyItsStatus(): void
    {
        self::assertSame(['status' => 'failed'], JsonMappers::commentsJson()->one(CommentsResultModel::failed()));
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function firstComment(array $json): array
    {
        self::assertIsArray($json['comments']);
        $first = $json['comments'][0];
        self::assertIsArray($first);

        return array_combine(array_map(strval(...), array_keys($first)), $first);
    }
}
