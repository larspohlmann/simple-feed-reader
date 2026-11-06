<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\SocialCountsModel;
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
}
