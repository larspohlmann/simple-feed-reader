<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Discovery\FeedDiscovery;

use App\Service\Sourcing\Discovery\Exception\SiteThrottledException;
use App\Service\Sourcing\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Sourcing\Discovery\Model\ScrapeFallback;

interface FeedDiscoveryInterface
{
    /**
     * Never throws for an unreachable or feedless address; with $fallback off, a feedless page yields no reason.
     *
     * @throws SiteThrottledException when the site asked us to slow down
     */
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel;
}
