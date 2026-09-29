<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\HtmlItemExtractor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the `app.scrape_layer` tag and its priorities wire: the unit tests hand the extractor its layers as a plain
 * array, so they stay green even when the container's iterator collects nothing.
 */
final class HtmlItemExtractorWiringTest extends KernelTestCase
{
    use ScrapedFixtures;

    public function testTheContainerCollectsTheLayersInPriorityOrder(): void
    {
        self::bootKernel();
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        // Reaches the last-priority cluster layer: fails on an empty iterator.
        $parsed = $extractor->extract(
            $this->scrapedFixture('tagesschau-2026-07-23.html'),
            'https://www.tagesschau.de/'
        );
        self::assertGreaterThanOrEqual(20, \count($parsed->entries));

        // Stops at the highest-priority JSON-LD layer: fails on a wrong order.
        $parsed = $extractor->extract($this->scrapedFixture('jsonld-list.html'), 'https://news.test/section/');
        self::assertCount(4, $parsed->entries);

        // Stops at the semantic layer before clustering: a cluster win would
        // return the six /promo/ links instead of the five /posts/ articles.
        $parsed = $extractor->extract($this->scrapedFixture('articles-blog.html'), 'https://blog.test/');
        self::assertCount(5, $parsed->entries);
        foreach ($parsed->entries as $entry) {
            self::assertStringContainsString('/posts/', (string) $entry->url);
        }
    }
}
