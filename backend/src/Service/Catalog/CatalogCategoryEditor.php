<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogCategoryEditor
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogCategoryRequest $request): CatalogCategory
    {
        $category = new CatalogCategory($request->key, $request->name, $request->icon, $request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function update(CatalogCategory $category, CatalogCategoryRequest $request): void
    {
        $category->setName($request->name);
        $category->setIcon($request->icon);
        $category->setColor($request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $this->entityManager->flush();
    }

    public function delete(CatalogCategory $category): void
    {
        // The FK cascades to its catalog feeds; users' subscriptions are Feed rows and stay untouched.
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    public function reorder(ReorderRequest $request): void
    {
        $byId = [];
        foreach ($request->ids as $id) {
            $byId[$id] = $this->categories->getById($id);
        }
        $this->reorderer->reorder($request->ids, $byId);
    }
}
