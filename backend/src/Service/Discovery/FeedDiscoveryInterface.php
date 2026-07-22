<?php

declare(strict_types=1);

namespace App\Service\Discovery;

interface FeedDiscoveryInterface
{
    public function discover(string $url): FeedDiscoveryResult;
}
