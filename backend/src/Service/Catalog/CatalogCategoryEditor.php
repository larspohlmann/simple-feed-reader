<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Catalog\Factory\CatalogCategoryFactory;
use App\Service\Catalog\Model\CatalogCategoryDetailsModel;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogCategoryEditor
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
        private CatalogCategoryFactory $categoryFactory,
    ) {
    }

    public function create(CatalogCategoryDetailsModel $details): CatalogCategory
    {
        $category = $this->categoryFactory->create($details);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function update(CatalogCategory $category, CatalogCategoryDetailsModel $details): void
    {
        $category->setName($details->name);
        $category->setIcon($details->icon);
        $category->setColor($details->color);
        $category->setEnabled($details->enabled);
        $category->setLocked($details->locked);
        $this->entityManager->flush();
    }

    public function delete(CatalogCategory $category): void
    {
        // The FK cascades to its catalog feeds; users' subscriptions are Feed rows and stay untouched.
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedCategoryIds */
    public function reorder(array $orderedCategoryIds): void
    {
        $byId = [];
        foreach ($orderedCategoryIds as $id) {
            $byId[$id] = $this->categories->getById($id);
        }
        $this->reorderer->reorder($orderedCategoryIds, $byId);
    }
}
