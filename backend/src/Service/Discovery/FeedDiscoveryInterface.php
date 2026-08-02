<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\ScrapeFallback;

interface FeedDiscoveryInterface
{
    /**
     * Never throws for an unreachable or feedless address: those are expected
     * outcomes the subscribe UI must render, so they surface as
     * FeedDiscoveryResult::$scrapeFailureReason
     * ('blocked'|'unreachable'|'not_scrapable') instead of an exception.
     * Callers can rely on always getting a result back to translate.
     *
     * With $fallback disabled, a page that advertises no feed yields an empty
     * candidate list and NO reason: 'not_scrapable' would tell the user about
     * a feature they have not turned on.
     */
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult;
}
