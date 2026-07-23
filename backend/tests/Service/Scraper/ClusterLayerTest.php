<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\ClusterLayer;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ClusterLayerTest extends TestCase
{
    use ScrapedFixtures;

    /** @return list<\App\Service\Scraper\ScrapedItem> */
    private function extract(string $fixture, string $baseUrl): array
    {
        $doc = HTMLDocument::createFromString($this->scrapedFixture($fixture), \LIBXML_NOERROR);

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

    /**
     * Regression guard for the container-ascent memoization: thousands of
     * sibling anchors under one parent used to trigger an O(N^2) rescan of
     * that parent (about 10s at 2,000 anchors). No timing assertion — the
     * suite duration itself is the tell — but the extraction must stay
     * correct on this degenerate shape.
     */
    public function testFlatPageWithThousandsOfSiblingAnchorsStillExtracts(): void
    {
        $links = '';
        for ($i = 0; $i < 2000; $i++) {
            $links .= sprintf('<a href="/p/%d">Story number %d headline</a>', $i, $i);
        }
        $doc = HTMLDocument::createFromString(
            '<html lang="en"><body><main>' . $links . '</main></body></html>',
            \LIBXML_NOERROR
        );

        $items = new ClusterLayer()->extract($doc, 'https://flat.test/');

        self::assertCount(2000, $items);
        self::assertSame('https://flat.test/p/0', $items[0]->url);
        self::assertSame('Story number 0 headline', $items[0]->title);
    }
}
