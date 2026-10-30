<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Search\Model\SearchTermsModel;
use Doctrine\ORM\QueryBuilder;

/** One search's terms compiled into a predicate, by SQLite's search index or MySQL's LIKEs. */
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
        if (DatabasePlatform::isMySql($qb->getEntityManager()->getConnection())) {
            return $this->likes->build($qb, $terms, $prefix, $entryAlias);
        }

        return $this->index->build($qb, $terms, $prefix, $entryAlias);
    }
}
