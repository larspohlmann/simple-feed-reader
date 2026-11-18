<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\TrendingWindow;
use App\Http\TrendingJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\StoryArticleModel;
use App\Service\Curation\Trending\Model\StoryArticlesModel;
use App\Service\Curation\Trending\Model\TrendingPageModel;
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

        $page = (new TrendingJson(JsonMappers::entryJson()))
            ->page(new TrendingPageModel(TrendingWindow::Day, null, [$card]));
        $story = $page['stories'][0];

        self::assertSame(12, $story['id']);
        self::assertSame(4, $story['outletCount']);
        self::assertFalse($story['viewed']);
        self::assertSame(3, $story['shareCount']);
        self::assertTrue($story['followed']);
        self::assertSame(501, $story['leadEntryId']);
        $articles = $story['articles'];
        self::assertIsList($articles);
        self::assertSame([501, 502, 503], array_column($articles, 'id'));
        self::assertIsArray($articles[0]);
        $shares = $articles[0]['shares'];
        self::assertIsArray($shares);
        self::assertSame([601, 602], array_column($shares, 'id'));
    }

    public function testAStoryCarriesItsTitle(): void
    {
        $ranked = (new RankedStoryModel(12, 2, new \DateTimeImmutable('2031-05-14 10:00:00')))
            ->titled('Hafenstreik in Hamburg');

        self::assertSame('Hafenstreik in Hamburg', $this->storyOf($ranked)['title']);
    }

    public function testAStoryWithoutStoredTitlesShowsItsLeadHeadline(): void
    {
        $ranked = new RankedStoryModel(12, 2, new \DateTimeImmutable('2031-05-14 10:00:00'));

        self::assertSame('Entry 501', $this->storyOf($ranked)['title']);
    }

    public function testEveryArticleAndShareKeepsTheEntryRowShape(): void
    {
        $card = new TrendingStoryCardModel(
            new StoryArticlesModel(new StoryArticleModel($this->row(501), [$this->row(601)]), []),
            new RankedStoryModel(12, 2, new \DateTimeImmutable('2031-05-14 10:00:00')),
            false,
            false,
        );
        $entryRow = array_keys(JsonMappers::entryJson()->listRow($this->row(700)));

        $page = (new TrendingJson(JsonMappers::entryJson()))
            ->page(new TrendingPageModel(TrendingWindow::Day, null, [$card]));

        $articles = $page['stories'][0]['articles'];
        self::assertIsList($articles);
        $article = $articles[0];
        self::assertIsArray($article);
        self::assertSame([...$entryRow, 'shares'], array_keys($article));
        $shares = $article['shares'];
        self::assertIsArray($shares);
        self::assertIsArray($shares[0]);
        self::assertSame($entryRow, array_keys($shares[0]));
    }

    /** @return array<string, mixed> */
    private function storyOf(RankedStoryModel $ranked): array
    {
        $card = new TrendingStoryCardModel(
            new StoryArticlesModel(new StoryArticleModel($this->row(501), []), []),
            $ranked,
            false,
            false,
        );

        return (new TrendingJson(JsonMappers::entryJson()))
            ->page(new TrendingPageModel(TrendingWindow::Day, null, [$card]))['stories'][0];
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
