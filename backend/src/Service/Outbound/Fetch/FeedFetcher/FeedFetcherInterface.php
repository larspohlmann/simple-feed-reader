<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\FeedFetcher;

use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Model\FetchResponseModel;

interface FeedFetcherInterface
{
    /**
     * Fetch a URL with SSRF protection, unconditionally.
     *
     * @throws FetchException
     */
    public function fetch(string $url): FetchResponseModel;
}
