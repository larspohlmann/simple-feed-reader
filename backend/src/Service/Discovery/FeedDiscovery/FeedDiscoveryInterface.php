<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedDiscovery;

use App\Service\Discovery\FeedDiscoveryResult;
use App\Service\Discovery\ScrapeFallback;

interface FeedDiscoveryInterface
{
    /** Never throws for an unreachable or feedless address; with $fallback off, a feedless page yields no reason. */
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult;
}
