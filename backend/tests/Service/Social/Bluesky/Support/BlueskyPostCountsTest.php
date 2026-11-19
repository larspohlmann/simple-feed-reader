<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\BlueskyPostCounts;
use App\Service\Social\Social\Model\SocialCountsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyPostCountsTest extends TestCase
{
    private const array BLUESKY_POST = ['likeCount' => 12, 'repostCount' => 3, 'replyCount' => 4, 'quoteCount' => 1];

    public function testABlueskyPostViewGivesItsFourCounts(): void
    {
        self::assertEquals(
            new SocialCountsModel(12, 3, 4, 1),
            BlueskyPostCounts::parsePost(JsonNodeModel::fromValue(self::BLUESKY_POST)),
        );
    }

    public function testABlueskyPostWithoutAQuoteCountHasNoQuotes(): void
    {
        $post = JsonNodeModel::fromValue(['likeCount' => 12, 'repostCount' => 3, 'replyCount' => 4]);

        self::assertEquals(new SocialCountsModel(12, 3, 4, null), BlueskyPostCounts::parsePost($post));
    }

    #[DataProvider('blueskyCounts')]
    public function testABlueskyPostMissingACountHasNoCounts(string $count): void
    {
        $post = self::BLUESKY_POST;
        unset($post[$count]);

        self::assertNull(BlueskyPostCounts::parsePost(JsonNodeModel::fromValue($post)));
    }

    /** @return iterable<string, array{string}> */
    public static function blueskyCounts(): iterable
    {
        yield 'likes' => ['likeCount'];
        yield 'reposts' => ['repostCount'];
        yield 'replies' => ['replyCount'];
    }
}
