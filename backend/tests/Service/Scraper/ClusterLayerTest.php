<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\ClusterLayer;
use PHPUnit\Framework\TestCase;

final class ClusterLayerTest extends TestCase
{
    /** @return list<\App\Service\Scraper\ScrapedItem> */
    private function extract(string $fixture, string $baseUrl): array
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $fixture);
        $doc = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        return new ClusterLayer()->extract($doc, $baseUrl);
    }

    public function testFindsTagesschauTeaserCluster(): void
    {
        $items = $this->extract('tagesschau-2026-07-23.html', 'https://www.tagesschau.de/');
        self::assertGreaterThanOrEqual(20, \count($items));
        $first = $items[0];
        self::assertStringNotContainsString("\u{00AD}", $first->title);
        self::assertMatchesRegularExpression('#^https://www\.tagesschau\.de/#', $first->url);
        $withTeaser = array_filter($items, static fn ($i) => $i->teaser !== null);
        self::assertGreaterThanOrEqual(10, \count($withTeaser));
    }

    public function testChromeOnlyPagesYieldNothing(): void
    {
        self::assertSame([], $this->extract('nav-only.html', 'https://nav.test/'));
    }

    public function testFooterListDoesNotBeatRealCards(): void
    {
        $items = $this->extract('footer-links.html', 'https://footer.test/');
        self::assertCount(3, $items);
        self::assertStringContainsString('/posts/', $items[0]->url);
    }
}
