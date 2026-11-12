<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Discovery\Exception\SiteThrottledException;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;

final class ThrottledFeedDiscovery implements FeedDiscoveryInterface
{
    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        throw new SiteThrottledException();
    }
}
