<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;

interface FeedFetcherInterface
{
    /**
     * Fetch a URL with SSRF protection, unconditionally.
     *
     * @throws FetchException
     */
    public function fetch(string $url): FetchResponse;
}
