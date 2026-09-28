<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Service\Image\Exception\FaviconRejectedException;
use App\Service\Image\Exception\FaviconUnavailableException;

interface CatalogFaviconFetcherInterface
{
    /**
     * Download the bytes of one already-resolved icon URL under the SSRF/size/type guards.
     *
     * @throws FaviconRejectedException when the host or this fetcher's policy refused; the resource may still be valid
     * @throws FaviconUnavailableException
     */
    public function download(string $iconUrl): FetchedFavicon;
}
