<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\JsonLdLayer;
use PHPUnit\Framework\TestCase;

final class JsonLdLayerTest extends TestCase
{
    /** @return list<\App\Service\Scraper\ScrapedItem> */
    private function extract(string $fixture): array
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $fixture);
        $doc = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        return new JsonLdLayer()->extract($doc, 'https://news.test/section/');
    }

    public function testExtractsItemListWithFields(): void
    {
        $items = $this->extract('jsonld-list.html');
        self::assertCount(4, $items);
        self::assertSame('https://news.test/story-1', $items[0]->url);
        self::assertNotNull($items[0]->teaser);
        self::assertNotNull($items[0]->imageUrl);
        self::assertSame('2026-07-20', $items[0]->publishedAt?->format('Y-m-d'));
    }

    public function testIgnoresPagesWithoutArticleStructures(): void
    {
        $doc = \Dom\HTMLDocument::createFromString(
            '<html><body><script type="application/ld+json">{"@type":"Organization","name":"X"}</script>'
            . '</body></html>',
            \LIBXML_NOERROR
        );
        self::assertSame([], new JsonLdLayer()->extract($doc, 'https://news.test/'));
    }
}
