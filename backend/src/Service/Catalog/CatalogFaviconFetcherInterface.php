<?php

declare(strict_types=1);

namespace App\Service\Catalog;

interface CatalogFaviconFetcherInterface
{
    /**
     * Download the bytes of one already-resolved icon URL under the SSRF/size/type guards.
     *
     * @throws \App\Service\Catalog\Exception\FaviconUnavailableException
     */
    public function download(string $iconUrl): FetchedFavicon;
}
