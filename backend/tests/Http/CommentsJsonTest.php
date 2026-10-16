<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\EntryMedia;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Http\CommentsJson;
use App\Http\QuotedPostJson;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\EntryCommentModel;
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
        );

        $json = CommentsJson::one(CommentsResultModel::ok(
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
            ],
            'quote' => QuotedPostJson::ofOrNull($quote),
        ], self::firstComment($json));
    }

    public function testACommentWithoutEmbedsHasNoCardNoQuoteAndNoMedia(): void
    {
        $comment = new EntryCommentModel(new CommentAuthorModel(null), null, null, '', false);

        $json = self::firstComment(CommentsJson::one(CommentsResultModel::ok(
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
            CommentsJson::one(CommentsResultModel::throttled(30)),
        );
    }

    public function testAFailedResultCarriesOnlyItsStatus(): void
    {
        self::assertSame(['status' => 'failed'], CommentsJson::one(CommentsResultModel::failed()));
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
