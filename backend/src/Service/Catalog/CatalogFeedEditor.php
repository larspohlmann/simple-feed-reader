<?php

declare(strict_types=1);

namespace App\Service\Catalog;

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

    public function create(CatalogFeedDetails $details): CatalogFeed
    {
        $category = $this->categories->getById($details->categoryId);
        $feed = new CatalogFeed($category, $details->title, $details->url);
        $this->applyEditableFields($feed, $details);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedDetails $details): void
    {
        $feed->setCategory($this->categories->getById($details->categoryId));
        $feed->setTitle($details->title);
        $feed->setUrl($details->url);
        $this->applyEditableFields($feed, $details);
        $this->entityManager->flush();
    }

    public function delete(CatalogFeed $feed): void
    {
        $this->entityManager->remove($feed);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedFeedIds */
    public function reorder(array $orderedFeedIds): void
    {
        $byId = [];
        foreach ($orderedFeedIds as $id) {
            $byId[$id] = $this->feeds->getById($id);
        }
        $this->reorderer->reorder($orderedFeedIds, $byId);
    }

    private function applyEditableFields(CatalogFeed $feed, CatalogFeedDetails $details): void
    {
        $feed->setSiteUrl($details->siteUrl);
        $feed->setDescription($details->description);
        $feed->setSourceFormat($details->sourceFormat);
        $feed->setEnabled($details->enabled);
        $feed->setLocked($details->locked);
    }
}
