<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Search\Model\SearchTermsModel;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\QueryBuilder;

/**
 * One search's terms compiled into a predicate and bound onto the QueryBuilder it is handed: SQLite asks its search
 * index, whose LIKE would fold ASCII case alone; MySQL's collation lets plain LIKEs ignore case and accents.
 */
final readonly class SearchTermsPredicateBuilder
{
    public function __construct(
        private LikeSearchPredicates $likes,
        private IndexSearchPredicates $index,
    ) {
    }

    /**
     * The terms as one ANDed expression the caller places, since DatabaseSavedSearchMatcher ORs several of them.
     * $prefix keys the bound parameters, so two searches sharing a word cannot overwrite each other's value.
     */
    public function build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias = 'e'): string
    {
        if ($qb->getEntityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            return $this->index->build($qb, $terms, $prefix, $entryAlias);
        }

        return $this->likes->build($qb, $terms, $prefix, $entryAlias);
    }
}
