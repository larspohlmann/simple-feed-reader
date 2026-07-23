<?php

declare(strict_types=1);

namespace App\Service\Discovery;

interface FeedDiscoveryInterface
{
    /**
     * Never throws for an unreachable or feedless address: those are expected
     * outcomes the subscribe UI must render, so they surface as
     * FeedDiscoveryResult::$scrapeFailureReason
     * ('blocked'|'unreachable'|'not_scrapable') instead of an exception.
     * Callers can rely on always getting a result back to translate.
     */
    public function discover(string $url): FeedDiscoveryResult;
}
