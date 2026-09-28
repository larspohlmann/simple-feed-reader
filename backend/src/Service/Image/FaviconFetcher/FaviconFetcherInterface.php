<?php

declare(strict_types=1);

namespace App\Service\Image\FaviconFetcher;

use App\Service\Image\Exception\FaviconRejectedException;
use App\Service\Image\Exception\FaviconUnavailableException;
use App\Service\Image\FetchedFavicon;

interface FaviconFetcherInterface
{
    /**
     * Download the bytes of one already-resolved icon URL under the SSRF/size/type guards.
     *
     * @throws FaviconRejectedException when the host or this fetcher's policy refused; the resource may still be valid
     * @throws FaviconUnavailableException
     */
    public function download(string $iconUrl): FetchedFavicon;
}
