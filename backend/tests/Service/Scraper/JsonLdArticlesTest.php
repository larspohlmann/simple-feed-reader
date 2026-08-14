<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Fetch\PageUrls;
use App\Service\Scraper\CardFields;
use App\Service\Scraper\JsonLdArticles;
use App\Service\Scraper\ScrapedItem;
use PHPUnit\Framework\TestCase;

/**
 * The layer test covers the block shapes a real page ships. This one pins the
 * field rules at their edges, which a fixture page cannot state precisely.
 */
final class JsonLdArticlesTest extends TestCase
{
    private function articles(): JsonLdArticles
    {
        return new JsonLdArticles(new PageUrls('https://news.test/section/'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function articleNode(array $overrides = []): array
    {
        return $overrides + ['@type' => 'Article', 'url' => '/a/1', 'headline' => 'A headline long enough'];
    }

    private function firstOf(JsonLdArticles $articles): ScrapedItem
    {
        $items = $articles->all();
        self::assertCount(1, $items);

        return $items[0];
    }

    public function testAGraphIsCollectedOnceEvenWhenItsWrapperAlsoNamesAnItemList(): void
    {
        $articles = $this->articles();
        $articles->collect([
            '@type' => 'ItemList',
            '@graph' => [$this->articleNode()],
            'itemListElement' => [$this->articleNode(['url' => '/a/2'])],
        ]);

        self::assertSame(['https://news.test/a/1'], array_column($articles->all(), 'url'));
    }

    public function testANodeTypedBothAsAListAndAnArticleIsReadAsTheListAlone(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode([
            '@type' => ['ItemList', 'Article'],
            'itemListElement' => [$this->articleNode(['url' => '/a/2'])],
        ]));

        self::assertSame(['https://news.test/a/2'], array_column($articles->all(), 'url'));
    }

    public function testHeadlineWinsOverName(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['name' => 'The name that must lose']));

        self::assertSame('A headline long enough', $this->firstOf($articles)->title);
    }

    public function testATitleShorterThanTheMinimumRejectsTheNode(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['headline' => str_repeat('x', CardFields::MIN_TITLE_LENGTH - 1)]));

        self::assertSame([], $articles->all());
    }

    public function testATitleOfExactlyTheMinimumLengthIsKept(): void
    {
        $shortest = str_repeat('x', CardFields::MIN_TITLE_LENGTH);
        $articles = $this->articles();
        $articles->collect($this->articleNode(['headline' => $shortest]));

        self::assertSame($shortest, $this->firstOf($articles)->title);
    }

    public function testAnOverlongTitleIsTruncatedToTheMaximum(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['headline' => str_repeat('x', CardFields::MAX_TITLE_LENGTH + 1)]));

        self::assertSame(
            str_repeat('x', CardFields::MAX_TITLE_LENGTH),
            $this->firstOf($articles)->title,
        );
    }

    public function testTheLengthRulesCountCharactersNotBytes(): void
    {
        // 'ä' is two bytes: a byte-counting title rule would accept this
        // four-character headline and cut the overlong one mid-character.
        $articles = $this->articles();
        $articles->collect($this->articleNode(['headline' => str_repeat('ä', CardFields::MIN_TITLE_LENGTH - 1)]));

        self::assertSame([], $articles->all());
    }

    public function testAnOverlongMultibyteTitleIsCutOnACharacterBoundary(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['headline' => str_repeat('ä', CardFields::MAX_TITLE_LENGTH + 1)]));

        self::assertSame(
            str_repeat('ä', CardFields::MAX_TITLE_LENGTH),
            $this->firstOf($articles)->title,
        );
    }

    public function testAMultibyteTeaserShorterThanTheMinimumIsDropped(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode([
            'description' => str_repeat('ä', CardFields::MIN_TEASER_LENGTH - 1),
        ]));

        self::assertNull($this->firstOf($articles)->teaser);
    }

    public function testATeaserShorterThanTheMinimumIsDropped(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode([
            'description' => str_repeat('x', CardFields::MIN_TEASER_LENGTH - 1),
        ]));

        self::assertNull($this->firstOf($articles)->teaser);
    }

    public function testATeaserOfExactlyTheMinimumLengthIsKept(): void
    {
        $shortest = str_repeat('x', CardFields::MIN_TEASER_LENGTH);
        $articles = $this->articles();
        $articles->collect($this->articleNode(['description' => $shortest]));

        self::assertSame($shortest, $this->firstOf($articles)->teaser);
    }

    public function testAnImageObjectIsReadThroughItsUrlField(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['image' => ['url' => '/img/one.jpg']]));

        self::assertSame('https://news.test/img/one.jpg', $this->firstOf($articles)->imageUrl);
    }

    public function testTheFirstImageOfAListWins(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['image' => ['/img/one.jpg', '/img/two.jpg']]));

        self::assertSame('https://news.test/img/one.jpg', $this->firstOf($articles)->imageUrl);
    }

    public function testANodeTypedByAListThatMixesInNonStringsStillMatches(): void
    {
        $articles = $this->articles();
        $articles->collect($this->articleNode(['@type' => [['nested'], 'Article']]));

        self::assertCount(1, $articles->all());
    }
}
