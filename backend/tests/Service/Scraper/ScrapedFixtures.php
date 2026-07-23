<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

/**
 * Loads saved scraped-page fixtures (tests/Fixtures/scraped) — the loader
 * used to be copy-pasted across all five scraper test classes.
 */
trait ScrapedFixtures
{
    private function scrapedFixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $name);
    }
}
