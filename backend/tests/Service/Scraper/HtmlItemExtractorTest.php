<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

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

    public function testJsonLdWinsOverClustering(): void
    {
        // jsonld-list.html carries exactly 4 JSON-LD items; if clustering ran
        // instead, the count would differ (the fixture also contains link markup).
        $parsed = $this->extractor()->extract($this->fixture('jsonld-list.html'), 'https://news.test/section/');
        self::assertCount(4, $parsed->entries);
    }

    public function testHostilePagesThrow(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->extractor()->extract($this->fixture('nav-only.html'), 'https://nav.test/');
    }

    public function testUnparseableInputThrows(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->extractor()->extract('', 'https://empty.test/');
    }
}
