<?php

declare(strict_types=1);

namespace App\Service\Discovery\Exception;

/** An account with the scrape fallback off asked for a scraped source (ScrapeFallbackPolicy::assertMayScrape()). */
final class ScrapingDisabledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Website scraping is turned off for this account.');
    }
}
