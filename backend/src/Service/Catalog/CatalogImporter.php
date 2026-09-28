<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
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
        private EntityManagerInterface $em,
    ) {
    }

    public function import(ParsedCatalog $document, CatalogImportMode $mode): CatalogImportResult
    {
        return $this->em->wrapInTransaction(function () use ($document, $mode): CatalogImportResult {
            $pass = new CatalogImportPass($this->em, $this->categories->findAllOrdered(), $this->feeds->findAll());
            $pass->apply($document);
            if (CatalogImportMode::Replace === $mode) {
                $pass->removeUnmentioned();
            }

            $this->em->flush();

            return $pass->result();
        });
    }
}
