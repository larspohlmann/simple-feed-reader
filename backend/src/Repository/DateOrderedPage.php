<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\EntryPlanHint;
use App\Doctrine\EntryPlanHintWalker;
use App\Enum\ListOrder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * Runs a fan-in page; a chronological fan-in is walked hinted inside a window. A short window falls back unhinted,
 * except an all/unread scope beyond the window, which first tries a wider window or, too big to sort, the full walk.
 */
final readonly class DateOrderedPage
{
    public const int DEFAULT_WINDOW_SIZE = 2000;
    public const int DEFAULT_WIDE_WINDOW_SIZE = 20000;
    public const int DEFAULT_PLAIN_SCOPE_LIMIT = 20000;

    public function __construct(
        private int $windowSize = self::DEFAULT_WINDOW_SIZE,
        private int $wideWindowSize = self::DEFAULT_WIDE_WINDOW_SIZE,
        private int $plainScopeLimit = self::DEFAULT_PLAIN_SCOPE_LIMIT,
    ) {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function rows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        if (!$query->isDateOrderedFanIn()) {
            return $this->plainResult(($queries->page)());
        }

        return $this->windowedRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function windowedRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        $windowEdge = $this->windowEdge($queries->windowProbe, $this->windowSize);
        if ($windowEdge === null) {
            return $this->hintedResult(($queries->page)());
        }

        $windowed = $this->hintedResult($this->windowed(($queries->page)(), $windowEdge, $query->order));
        if (\count($windowed) === $query->limit) {
            return $windowed;
        }

        return $this->fallbackRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function fallbackRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        if ($query->isNarrowed() || !$this->scopeReaches($queries->scopeProbe, $this->windowSize)) {
            return $this->plainResult(($queries->page)());
        }
        if ($this->scopeReaches($queries->scopeProbe, $this->plainScopeLimit)) {
            return $this->hintedResult(($queries->page)());
        }

        return $this->wideWindowedRows($query, $queries);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function wideWindowedRows(EntryQuery $query, DateOrderedQueries $queries): array
    {
        $wideEdge = $this->windowEdge($queries->windowProbe, $this->wideWindowSize);
        if ($wideEdge === null) {
            return $this->hintedResult(($queries->page)());
        }

        $windowed = $this->hintedResult($this->windowed(($queries->page)(), $wideEdge, $query->order));
        if (\count($windowed) === $query->limit) {
            return $windowed;
        }

        return $this->plainResult(($queries->page)());
    }

    /**
     * @param \Closure(): QueryBuilder $scopeProbe
     */
    private function scopeReaches(\Closure $scopeProbe, int $entryCount): bool
    {
        $beyondTheCount = $scopeProbe()
            ->setFirstResult($entryCount)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $beyondTheCount !== null;
    }

    /**
     * The effectiveDate $windowSize rows beyond the cursor in the page's own order, or null when fewer lie beyond it.
     *
     * @param \Closure(): QueryBuilder $windowProbe
     */
    private function windowEdge(\Closure $windowProbe, int $windowSize): ?\DateTimeImmutable
    {
        /** @var array{effectiveDate: \DateTimeImmutable}|null $pivot */
        $pivot = $windowProbe()
            ->setFirstResult($windowSize)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $pivot === null ? null : $pivot['effectiveDate'];
    }

    private function windowed(QueryBuilder $pageQuery, \DateTimeImmutable $windowEdge, ListOrder $order): QueryBuilder
    {
        return $pageQuery
            ->andWhere(\sprintf('e.effectiveDate %s :windowEdge', $order->atOrBefore()))
            ->setParameter('windowEdge', $windowEdge, Types::DATETIME_IMMUTABLE);
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
