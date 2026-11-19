<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Mastodon\Support\MastodonPostCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonPostCountsTest extends TestCase
{
    private const array MASTODON_STATUS = ['favourites_count' => 5, 'reblogs_count' => 2, 'replies_count' => 1];

    public function testAMastodonStatusBefore45GivesItsCountsAndNoQuotes(): void
    {
        self::assertEquals(
            new SocialCountsModel(5, 2, 1, null),
            MastodonPostCounts::parseStatus(JsonNodeModel::of(self::MASTODON_STATUS)),
        );
    }

    public function testAMastodon45StatusCountsItsQuotes(): void
    {
        self::assertEquals(
            new SocialCountsModel(5, 2, 1, 3),
            MastodonPostCounts::parseStatus(JsonNodeModel::of([...self::MASTODON_STATUS, 'quotes_count' => 3])),
        );
    }

    #[DataProvider('mastodonCounts')]
    public function testAMastodonStatusMissingACountHasNoCounts(string $count): void
    {
        $status = self::MASTODON_STATUS;
        unset($status[$count]);

        self::assertNull(MastodonPostCounts::parseStatus(JsonNodeModel::of($status)));
    }

    /** @return iterable<string, array{string}> */
    public static function mastodonCounts(): iterable
    {
        yield 'favourites' => ['favourites_count'];
        yield 'reblogs' => ['reblogs_count'];
        yield 'replies' => ['replies_count'];
    }
}
