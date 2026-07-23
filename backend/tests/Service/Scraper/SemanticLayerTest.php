<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\SemanticLayer;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class SemanticLayerTest extends TestCase
{
    /** @return list<\App\Service\Scraper\ScrapedItem> */
    private function extract(string $fixture, string $baseUrl): array
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $fixture);
        $doc = HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        return new SemanticLayer()->extract($doc, $baseUrl);
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
        self::assertSame([], new SemanticLayer()->extract($doc, 'https://blog.test/'));
    }
}
