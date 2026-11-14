<?php

declare(strict_types=1);

namespace App\Service\Catalog\Factory;

use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Model\CatalogFeedDetailsModel;

final readonly class CatalogFeedFactory
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
    ) {
    }

    public function create(CatalogFeedDetailsModel $details): CatalogFeed
    {
        $category = $this->categories->getById($details->categoryId);
        $feed = new CatalogFeed($category, $details->title, $details->url);
        $feed->setSiteUrl($details->siteUrl);
        $feed->setDescription($details->description);
        $feed->setSourceFormat($details->sourceFormat);
        $feed->setEnabled($details->enabled);
        $feed->setLocked($details->locked);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));

        return $feed;
    }
}
