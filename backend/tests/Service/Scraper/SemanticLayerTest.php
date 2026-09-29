<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\CardTitle;
use App\Service\Scraper\ScrapeLayer\SemanticLayer;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class SemanticLayerTest extends TestCase
{
    use ScrapedFixtures;

    /** @return list<\App\Service\Scraper\Model\ScrapedItemModel> */
    private function extract(string $fixture, string $baseUrl): array
    {
        $doc = HTMLDocument::createFromString($this->scrapedFixture($fixture), \LIBXML_NOERROR);

        return new SemanticLayer(new CardTitle())->extract($doc, $baseUrl);
    }

    public function testExtractsRepeatedArticleElements(): void
    {
        $items = $this->extract('articles-blog.html', 'https://blog.test/');
        self::assertCount(5, $items);
        self::assertStringStartsWith('https://blog.test/', $items[0]->url);
        self::assertNotNull($items[0]->teaser);
    }

    public function testFewerThanThreeArticlesYieldsNothing(): void
    {
        $doc = HTMLDocument::createFromString(
            '<html lang="en"><body><article><h2><a href="/one">Single article headline</a></h2></article>'
            . '</body></html>',
            \LIBXML_NOERROR
        );
        self::assertSame([], new SemanticLayer(new CardTitle())->extract($doc, 'https://blog.test/'));
    }
}
