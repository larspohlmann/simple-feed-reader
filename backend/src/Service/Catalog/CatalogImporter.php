<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\Model\CatalogImportMode;
use App\Service\Catalog\Model\CatalogImportResultModel;
use App\Service\Catalog\Model\ParsedCatalogModel;
use App\Service\Catalog\Pass\CatalogImportPass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a validated catalog document in one transaction, matching rows by natural key (category key, feed URL) so
 * a surviving feed keeps its cached favicon. Locked rows belong to the admin: never overwritten, never removed.
 */
final readonly class CatalogImporter
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function import(ParsedCatalogModel $document, CatalogImportMode $mode): CatalogImportResultModel
    {
        return $this->entityManager->wrapInTransaction(function () use ($document, $mode): CatalogImportResultModel {
            $pass = new CatalogImportPass(
                $this->entityManager,
                $this->categories->findAllOrdered(),
                $this->feeds->findAll(),
            );
            $pass->apply($document);
            if (CatalogImportMode::Replace === $mode) {
                $pass->removeUnmentioned();
            }

            $this->entityManager->flush();

            return $pass->result();
        });
    }
}
