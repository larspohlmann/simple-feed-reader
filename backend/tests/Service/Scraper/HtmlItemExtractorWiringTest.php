<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\HtmlItemExtractor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The unit tests hand the extractor its layers as a plain array, so they stay
 * green even if the container's tagged iterator collects nothing — the same
 * silent failure mode OAuthProviderWiringTest documents for its registry.
 * This test proves the app.scrape_layer tag and the AsTaggedItem priorities
 * actually wire: an empty iterator would throw on the tagesschau fixture, and
 * a wrong order would let clustering (5 promo links) beat the four-item
 * JSON-LD list.
 */
final class HtmlItemExtractorWiringTest extends KernelTestCase
{
    public function testTheContainerCollectsTheLayersInPriorityOrder(): void
    {
        self::bootKernel();
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        // Reaches the last-priority cluster layer: fails on an empty iterator.
        $parsed = $extractor->extract($this->fixture('tagesschau-2026-07-23.html'), 'https://www.tagesschau.de/');
        self::assertGreaterThanOrEqual(20, \count($parsed->entries));

        // Stops at the highest-priority JSON-LD layer: fails on a wrong order.
        $parsed = $extractor->extract($this->fixture('jsonld-list.html'), 'https://news.test/section/');
        self::assertCount(4, $parsed->entries);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $name);
    }
}
