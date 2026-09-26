<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogFeedEditor
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogFeedRequest $request): CatalogFeed
    {
        $category = $this->categories->getById($request->categoryId);
        $feed = new CatalogFeed($category, $request->title, $request->url);
        $this->applyEditableFields($feed, $request);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setCategory($this->categories->getById($request->categoryId));
        $feed->setTitle($request->title);
        $feed->setUrl($request->url);
        $this->applyEditableFields($feed, $request);
        $this->entityManager->flush();
    }

    public function delete(CatalogFeed $feed): void
    {
        $this->entityManager->remove($feed);
        $this->entityManager->flush();
    }

    public function reorder(ReorderRequest $request): void
    {
        $byId = [];
        foreach ($request->ids as $id) {
            $byId[$id] = $this->feeds->getById($id);
        }
        $this->reorderer->reorder($request->ids, $byId);
    }

    private function applyEditableFields(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setSiteUrl($request->siteUrl);
        $feed->setDescription($request->description);
        $feed->setSourceFormat($request->sourceFormat);
        $feed->setEnabled($request->enabled);
        $feed->setLocked($request->locked);
    }
}
