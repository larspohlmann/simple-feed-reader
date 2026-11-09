<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

/** The statements a date-ordered fan-in page may run; each closure builds a fresh query per call. */
final readonly class DateOrderedQueries
{
    /**
     * @param \Closure(): QueryBuilder $page        the complete page query; called once per attempt
     * @param \Closure(): QueryBuilder $windowProbe an Entry-only effectiveDate query in the page's order, cursored
     * @param \Closure(): QueryBuilder $scopeProbe  in-scope entry ids, read state ignored, duplicates counted
     */
    public function __construct(
        public \Closure $page,
        public \Closure $windowProbe,
        public \Closure $scopeProbe,
    ) {
    }
}
