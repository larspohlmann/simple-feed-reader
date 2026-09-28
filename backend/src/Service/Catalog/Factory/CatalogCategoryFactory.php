<?php

declare(strict_types=1);

namespace App\Service\Catalog\Factory;

use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Catalog\CatalogCategoryDetails;

final readonly class CatalogCategoryFactory
{
    public function __construct(private CatalogCategoryRepository $categories)
    {
    }

    public function create(CatalogCategoryDetails $details): CatalogCategory
    {
        $category = new CatalogCategory($details->key, $details->name, $details->icon, $details->color);
        $category->setEnabled($details->enabled);
        $category->setLocked($details->locked);
        $category->setPosition($this->categories->nextPosition());

        return $category;
    }
}
