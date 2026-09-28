<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Service\Catalog\Model\CatalogFaviconModel;

/** Never fetches: a missing icon is a normal state that app:catalog:warm-favicons fills at deploy time. */
final readonly class CatalogFaviconSource
{
    public function __construct(private MonogramFavicon $monogram)
    {
    }

    public function imageFor(CatalogFeed $feed): CatalogFaviconModel
    {
        $bytes = $feed->getFaviconBytes();
        $contentType = $feed->getFaviconContentType();

        if (null === $bytes || null === $contentType) {
            return new CatalogFaviconModel($this->monogram->render($feed), MonogramFavicon::CONTENT_TYPE);
        }

        return new CatalogFaviconModel($bytes, $contentType);
    }
}
