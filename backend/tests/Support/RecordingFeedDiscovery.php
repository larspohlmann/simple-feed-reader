<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Sourcing\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Sourcing\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Sourcing\Discovery\Model\ScrapeFallback;

final class RecordingFeedDiscovery implements FeedDiscoveryInterface
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<ScrapeFallback> */
    public array $fallbacks = [];

    public function __construct(private readonly FeedDiscoveryResultModel $result)
    {
    }

    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
    {
        $this->urls[] = $url;
        $this->fallbacks[] = $fallback;

        return $this->result;
    }
}
