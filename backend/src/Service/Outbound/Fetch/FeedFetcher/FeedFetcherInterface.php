<?php

declare(strict_types=1);

namespace App\Service\Fetch\FeedFetcher;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\FetchResponseModel;

interface FeedFetcherInterface
{
    /**
     * Fetch a URL with SSRF protection, unconditionally.
     *
     * @throws FetchException
     */
    public function fetch(string $url): FetchResponseModel;
}
