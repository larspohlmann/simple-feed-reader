<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

/** The statements a date-ordered fan-in page may run; each closure builds a fresh query per call. */
final readonly class DateOrderedQueries
{
    /**
     * @param \Closure(): QueryBuilder $page        the complete page query; called up to twice
     * @param \Closure(): QueryBuilder $windowProbe an Entry-only effectiveDate query in the page's order, cursored
     * @param \Closure(): QueryBuilder $scopeProbe  the reader's in-scope entry ids, read state ignored
     */
    public function __construct(
        public \Closure $page,
        public \Closure $windowProbe,
        public \Closure $scopeProbe,
    ) {
    }
}
