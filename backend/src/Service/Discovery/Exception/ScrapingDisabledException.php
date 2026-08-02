<?php

declare(strict_types=1);

namespace App\Service\Subscription\Exception;

/**
 * A scraped source was requested by an account that has the experimental
 * scrape fallback turned off. Discovery never offers such a candidate to that
 * account, so this only arises from a hand-made request — which is exactly why
 * the check cannot live in discovery alone.
 */
final class ScrapingDisabledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Website scraping is turned off for this account.');
    }
}
