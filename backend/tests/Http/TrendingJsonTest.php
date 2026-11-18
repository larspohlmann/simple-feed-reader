<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\TrendingJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\StoryArticleModel;
use App\Service\Curation\Trending\Model\StoryArticlesModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;
use App\Tests\Support\JsonMappers;
use PHPUnit\Framework\TestCase;

final class TrendingJsonTest extends TestCase
{
    public function testAStoryCarriesItsArticlesLeadFirst(): void
    {
        $card = new TrendingStoryCardModel(
            new StoryArticlesModel(
                new StoryArticleModel($this->row(501), [$this->row(601), $this->row(602)]),
                [new StoryArticleModel($this->row(502), []), new StoryArticleModel($this->row(503), [$this->row(603)])],
            ),
            new RankedStoryModel(12, 4, new \DateTimeImmutable('2031-05-14 10:00:00')),
            false,
            true,
        );

        $story = (new TrendingJson(JsonMappers::entryJson()))->story($card);

        self::assertSame(12, $story['id']);
        self::assertSame(4, $story['outletCount']);
        self::assertFalse($story['viewed']);
        self::assertSame(3, $story['shareCount']);
        self::assertTrue($story['followed']);
        self::assertSame(501, $story['leadEntryId']);
        self::assertSame([501, 502, 503], array_column($story['articles'], 'id'));
        $shares = $story['articles'][0]['shares'];
        self::assertIsArray($shares);
        self::assertSame([601, 602], array_column($shares, 'id'));
        foreach ($story['articles'] as $article) {
            self::assertArrayNotHasKey('duplicates', $article);
            self::assertArrayNotHasKey('story', $article);
        }
    }

    private function row(int $id): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            "guid-$id",
            "https://example.com/$id",
            "Entry $id",
            new \DateTimeImmutable('2031-05-14T00:00:00Z'),
            new \DateTimeImmutable('2031-05-14T00:00:00Z'),
        );
        (new \ReflectionProperty(Entry::class, 'id'))->setValue($entry, $id);

        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'Source'),
            new EntryListRowMarks(isHidden: false, isFavorite: false, isKept: false, viewedAt: null, sharedAt: null),
            null,
        );
    }
}
