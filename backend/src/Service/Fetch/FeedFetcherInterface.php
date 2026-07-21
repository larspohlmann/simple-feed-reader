<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;

interface FeedFetcherInterface
{
    /**
     * Fetch a feed URL with SSRF protection and conditional-GET support.
     *
     * @throws FetchException
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse;
}
