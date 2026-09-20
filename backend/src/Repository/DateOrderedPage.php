<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\EntryPlanHint;
use App\Doctrine\EntryPlanHintWalker;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * Runs a fan-in page: unhinted for a single scope, hinted for the dense all/unread
 * views, and for a possibly-sparse tag scope probed, windowed, with an unhinted
 * fallback when the window is short (#1099).
 */
final readonly class DateOrderedPage
{
    public const int DEFAULT_WINDOW_SIZE = 2000;

    public function __construct(private int $windowSize = self::DEFAULT_WINDOW_SIZE)
    {
    }

    /**
     * @param callable(): QueryBuilder $pageQuery   a fresh, fully-built page
     *        query (ordered, scoped, collapsed, cursored, limited); called up
     *        to twice
     * @param callable(): QueryBuilder $windowProbe a fresh Entry-only query
     *        selecting e.effectiveDate, carrying the page's own cursor
     *
     * @return list<array<array-key, mixed>>
     */
    public function rows(EntryQuery $query, callable $pageQuery, callable $windowProbe): array
    {
        if (!$query->isDateOrderedFanIn()) {
            return $this->plainResult($pageQuery());
        }
        if (!$query->isTagScopedFanIn()) {
            return $this->hintedResult($pageQuery());
        }

        return $this->tagScopedRows($query, $pageQuery, $windowProbe);
    }

    /**
     * @param callable(): QueryBuilder $pageQuery
     * @param callable(): QueryBuilder $windowProbe
     *
     * @return list<array<array-key, mixed>>
     */
    private function tagScopedRows(EntryQuery $query, callable $pageQuery, callable $windowProbe): array
    {
        $windowStart = $this->windowStart($windowProbe);
        if ($windowStart === null) {
            return $this->hintedResult($pageQuery());
        }

        $windowed = $this->hintedResult($this->windowed($pageQuery(), $windowStart));
        if (\count($windowed) === $query->limit) {
            return $windowed;
        }

        return $this->plainResult($pageQuery());
    }

    /**
     * The K-th newest effectiveDate beyond the cursor, or null when fewer
     * than K rows lie beyond it.
     *
     * @param callable(): QueryBuilder $windowProbe
     */
    private function windowStart(callable $windowProbe): ?\DateTimeImmutable
    {
        /** @var array{effectiveDate: \DateTimeImmutable}|null $pivot */
        $pivot = $windowProbe()
            ->setFirstResult($this->windowSize)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $pivot === null ? null : $pivot['effectiveDate'];
    }

    private function windowed(QueryBuilder $pageQuery, \DateTimeImmutable $windowStart): QueryBuilder
    {
        return $pageQuery
            ->andWhere('e.effectiveDate >= :windowStart')
            ->setParameter('windowStart', $windowStart, Types::DATETIME_IMMUTABLE);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function hintedResult(QueryBuilder $qb): array
    {
        $query = $qb->getQuery();
        EntryPlanHintWalker::apply($query, EntryPlanHint::DateOrderedWalk);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function plainResult(QueryBuilder $qb): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
