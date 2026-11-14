<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Sourcing\Discovery\Exception\SiteThrottledException;
use App\Service\Sourcing\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Sourcing\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Sourcing\Discovery\Model\ScrapeFallback;

final class ThrottledFeedDiscovery implements FeedDiscoveryInterface
{
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        throw new SiteThrottledException();
    }
}
