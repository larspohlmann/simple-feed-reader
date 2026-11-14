<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Scraper;

trait ScrapedFixtures
{
    private function scrapedFixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $name);
    }
}
