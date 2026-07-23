<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\CardFields;
use App\Service\Scraper\Exception\HtmlExtractionException;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Scraper\Layer\ClusterLayer;
use App\Service\Scraper\Layer\JsonLdLayer;
use App\Service\Scraper\Layer\SemanticLayer;
use PHPUnit\Framework\TestCase;

final class HtmlItemExtractorTest extends TestCase
{
    private function extractor(): HtmlItemExtractor
    {
        return new HtmlItemExtractor([new JsonLdLayer(), new SemanticLayer(), new ClusterLayer()]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $name);
    }

    public function testTagesschauFullExtraction(): void
    {
        $parsed = $this->extractor()->extract(
            $this->fixture('tagesschau-2026-07-23.html'),
            'https://www.tagesschau.de/'
        );
        self::assertNotNull($parsed->title);
        self::assertSame('https://www.tagesschau.de/', $parsed->siteUrl);
        self::assertGreaterThanOrEqual(20, \count($parsed->entries));
        self::assertLessThanOrEqual(50, \count($parsed->entries));
        $urls = array_map(static fn ($e) => $e->url, $parsed->entries);
        self::assertSame($urls, array_unique($urls));
        $first = $parsed->entries[0];
        self::assertSame($first->url, $first->guid);
        self::assertNotNull($first->contentHtml);
        self::assertStringStartsWith('<p>', (string) $first->contentHtml);
    }

    public function testTreehuggerTitlesAndAttributeTeasers(): void
    {
        $parsed = $this->extractor()->extract(
            $this->fixture('treehugger-rendered-2026-07-23.html'),
            'https://www.treehugger.com/'
        );
        self::assertGreaterThanOrEqual(10, \count($parsed->entries));
        $titles = array_map(static fn ($e) => $e->title, $parsed->entries);
        self::assertContains('Your Yard’s Next Big Upgrade: A Rain Garden You Can Build Yourself', $titles);
        foreach ($titles as $t) {
            self::assertLessThan(301, mb_strlen($t));
        }
        $teasers = array_filter($parsed->entries, static fn ($e) => $e->summary !== null);
        self::assertNotEmpty($teasers);
    }

    public function testHeiseJsonLdExtractionWithAbstractTeasersAndCap(): void
    {
        $parsed = $this->extractor()->extract($this->fixture('heise-2026-07-23.html'), 'https://www.heise.de/');
        self::assertCount(50, $parsed->entries); // 141 unique urls, capped at 50
        foreach ($parsed->entries as $e) {
            self::assertMatchesRegularExpression('#^https://www\.heise\.de/#', (string) $e->url);
        }
        $teasers = array_filter($parsed->entries, static fn ($e) => $e->summary !== null);
        self::assertGreaterThanOrEqual(20, \count($teasers)); // abstracts arrived
        $images = array_filter($parsed->entries, static fn ($e) => $e->imageUrl !== null);
        self::assertGreaterThanOrEqual(20, \count($images));
    }

    public function testJsonLdWinsOverClustering(): void
    {
        // jsonld-list.html carries exactly 4 JSON-LD items; if clustering ran
        // instead, the count would differ (the fixture also contains link markup).
        $parsed = $this->extractor()->extract($this->fixture('jsonld-list.html'), 'https://news.test/section/');
        self::assertCount(4, $parsed->entries);
    }

    /**
     * The teaser length cap must hold at the funnel for every layer: JSON-LD
     * descriptions never pass through CardFields, so a clamp living only
     * there would let a 5,000-char description straight into the feed.
     */
    public function testTeaserCapAppliesToJsonLdDescriptionsAtTheFunnel(): void
    {
        $articles = [];
        for ($i = 1; $i <= 3; $i++) {
            $articles[] = [
                '@type' => 'NewsArticle',
                'url' => "/story-{$i}",
                'headline' => "Synthetic story number {$i}",
                'description' => str_repeat('x', 5000),
            ];
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $articles], \JSON_THROW_ON_ERROR);
        $html = '<html lang="en"><body><script type="application/ld+json">' . $json . '</script></body></html>';

        $parsed = $this->extractor()->extract($html, 'https://long.test/');

        self::assertCount(3, $parsed->entries);
        self::assertSame(CardFields::MAX_TEASER_LENGTH, mb_strlen((string) $parsed->entries[0]->summary));
    }

    public function testHostilePagesThrow(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->expectExceptionMessage('No article list');
        $this->extractor()->extract($this->fixture('nav-only.html'), 'https://nav.test/');
    }

    /** Empty input has its own message, distinguishable from a no-list page. */
    public function testUnparseableInputThrows(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->expectExceptionMessage('The page is empty.');
        $this->extractor()->extract('', 'https://empty.test/');
    }

    /**
     * MIN_ITEMS boundary at the facade: the best cluster has three anchors,
     * but one is a self-link the facade guard drops — the two surviving
     * items sit right below the minimum of three and must not become a feed.
     */
    public function testTwoItemsAfterTheFacadeGuardsAreBelowTheMinimum(): void
    {
        $html = <<<HTML
            <html lang="en"><body><main>
            <a class="card" href="https://mini.test/">Link back to this very page</a>
            <a class="card" href="/one">First real story headline</a>
            <a class="card" href="/two">Second real story headline</a>
            </main></body></html>
            HTML;
        $this->expectException(HtmlExtractionException::class);
        $this->expectExceptionMessage('No article list');
        $this->extractor()->extract($html, 'https://mini.test/');
    }
}
