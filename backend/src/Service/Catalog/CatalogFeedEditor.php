<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Factory\CatalogFeedFactory;
use App\Service\Catalog\Model\CatalogFeedDetailsModel;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogFeedEditor
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
        private CatalogFeedFactory $feedFactory,
    ) {
    }

    public function create(CatalogFeedDetailsModel $details): CatalogFeed
    {
        $feed = $this->feedFactory->create($details);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedDetailsModel $details): void
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
        $this->reorderer->reorderFound($orderedFeedIds, $this->feeds->getById(...));
    }

    private function applyEditableFields(CatalogFeed $feed, CatalogFeedDetailsModel $details): void
    {
        $feed->setSiteUrl($details->siteUrl);
        $feed->setDescription($details->description);
        $feed->setSourceFormat($details->sourceFormat);
        $feed->setEnabled($details->enabled);
        $feed->setLocked($details->locked);
    }
}
