<?php

declare(strict_types=1);

namespace App\Service\Fetch;

interface FaviconResolverInterface
{
    /**
     * Resolve a favicon URL for each site, fetching homepages concurrently.
     *
     * @param array<int, string> $baseUrlsByFeedId
     *
     * @return array<int, string|null> an https URL per input key, or null when the URL carried no host
     */
    public function resolveAll(array $baseUrlsByFeedId): array;
}
