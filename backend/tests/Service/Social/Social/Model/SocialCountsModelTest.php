<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Service\Content\Comments\Model\CommentCountsModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialCountsModelTest extends TestCase
{
    public function testBlueskyCountsSurviveTheCache(): void
    {
        $counts = new SocialCountsModel(12, 3, 4, 1);

        self::assertSame(['likes' => 12, 'reposts' => 3, 'replies' => 4, 'quotes' => 1], $counts->toCacheEntry());
        self::assertEquals($counts, SocialCountsModel::fromCacheEntryOrNull($counts->toCacheEntry()));
    }

    public function testMastodonCountsWithoutQuotesSurviveTheCache(): void
    {
        $counts = new SocialCountsModel(5, 2, 1, null);

        self::assertEquals($counts, SocialCountsModel::fromCacheEntryOrNull($counts->toCacheEntry()));
    }

    #[DataProvider('unreadableEntries')]
    public function testAnUnreadableCacheEntryReadsAsNoCounts(mixed $stored): void
    {
        self::assertNull(SocialCountsModel::fromCacheEntryOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableEntries(): iterable
    {
        yield 'no array' => ['12'];
        yield 'likes missing' => [['reposts' => 3, 'replies' => 4, 'quotes' => 1]];
        yield 'reposts as text' => [['likes' => 12, 'reposts' => '3', 'replies' => 4, 'quotes' => 1]];
        yield 'replies missing' => [['likes' => 12, 'reposts' => 3, 'quotes' => 1]];
        yield 'quotes as text' => [['likes' => 12, 'reposts' => 3, 'replies' => 4, 'quotes' => '1']];
    }

    public function testTheCountsCarryOverToAComment(): void
    {
        self::assertEquals(
            new CommentCountsModel(4, 3, 2, null),
            new SocialCountsModel(4, 3, 2, null)->toCommentCounts(),
        );
        self::assertEquals(new CommentCountsModel(1, 2, 3, 5), new SocialCountsModel(1, 2, 3, 5)->toCommentCounts());
    }
}
