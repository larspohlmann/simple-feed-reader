<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Support\PostCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostCountsTest extends TestCase
{
    private const array BLUESKY_POST = ['likeCount' => 12, 'repostCount' => 3, 'replyCount' => 4, 'quoteCount' => 1];
    private const array MASTODON_STATUS = ['favourites_count' => 5, 'reblogs_count' => 2, 'replies_count' => 1];

    public function testABlueskyPostViewGivesItsFourCounts(): void
    {
        self::assertEquals(
            new SocialCountsModel(12, 3, 4, 1),
            PostCounts::ofBlueskyPost(JsonNodeModel::of(self::BLUESKY_POST)),
        );
    }

    public function testABlueskyPostWithoutAQuoteCountHasNoQuotes(): void
    {
        $post = JsonNodeModel::of(['likeCount' => 12, 'repostCount' => 3, 'replyCount' => 4]);

        self::assertEquals(new SocialCountsModel(12, 3, 4, null), PostCounts::ofBlueskyPost($post));
    }

    #[DataProvider('blueskyCounts')]
    public function testABlueskyPostMissingACountHasNoCounts(string $count): void
    {
        $post = self::BLUESKY_POST;
        unset($post[$count]);

        self::assertNull(PostCounts::ofBlueskyPost(JsonNodeModel::of($post)));
    }

    /** @return iterable<string, array{string}> */
    public static function blueskyCounts(): iterable
    {
        yield 'likes' => ['likeCount'];
        yield 'reposts' => ['repostCount'];
        yield 'replies' => ['replyCount'];
    }

    public function testAMastodonStatusGivesItsCountsAndNoQuotes(): void
    {
        self::assertEquals(
            new SocialCountsModel(5, 2, 1, null),
            PostCounts::ofMastodonStatus(JsonNodeModel::of(self::MASTODON_STATUS)),
        );
    }

    #[DataProvider('mastodonCounts')]
    public function testAMastodonStatusMissingACountHasNoCounts(string $count): void
    {
        $status = self::MASTODON_STATUS;
        unset($status[$count]);

        self::assertNull(PostCounts::ofMastodonStatus(JsonNodeModel::of($status)));
    }

    /** @return iterable<string, array{string}> */
    public static function mastodonCounts(): iterable
    {
        yield 'favourites' => ['favourites_count'];
        yield 'reblogs' => ['reblogs_count'];
        yield 'replies' => ['replies_count'];
    }
}
