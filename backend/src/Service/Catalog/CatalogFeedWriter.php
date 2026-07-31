<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Dto\Admin\CatalogFeedRequest;
use App\Entity\CatalogFeed;

/**
 * Copies the editable fields of a catalog feed request onto the entity.
 *
 * A service, not a method on CatalogFeed: an entity method taking a request DTO
 * would couple the entity to the HTTP layer.
 */
final readonly class CatalogFeedWriter
{
    public function apply(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setSiteUrl($request->siteUrl);
        $feed->setDescription($request->description);
        $feed->setSourceFormat($request->sourceFormat);
        $feed->setEnabled($request->enabled);
        $feed->setLocked($request->locked);
    }
}
