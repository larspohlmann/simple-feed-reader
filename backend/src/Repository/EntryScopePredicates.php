<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * The entry-list scope filters, applied to a given EntryAliases set so the
 * primary query and the duplicate-collapse semi-join share one definition.
 * Holds no query state; the caller passes the aliases and the query.
 */
final readonly class EntryScopePredicates
{
    public function __construct(private SearchTermsPredicateBuilder $terms)
    {
    }

    public function applyList(QueryBuilder $qb, EntryAliases $a, EntryQuery $query): void
    {
        if ($query->subscriptionId !== null) {
            $qb->andWhere(\sprintf('%s.id = :sid', $a->subscription))
                ->setParameter('sid', $query->subscriptionId);
        }
        if ($query->tagId !== null) {
            $qb->innerJoin(
                \sprintf('%s.subscriptionTags', $a->subscription),
                $a->tag,
                'WITH',
                \sprintf('IDENTITY(%s.tag) = :tagId', $a->tag),
            )->setParameter('tagId', $query->tagId);
        }
        if ($query->hidesExcludedFeeds()) {
            $qb->andWhere(\sprintf('%s.includeInAllItems = true', $a->subscription));
        }
        $this->applyView($qb, $a, $query->view);
    }

    public function applySearch(QueryBuilder $qb, EntryAliases $a, EntrySearchQuery $query): void
    {
        $qb->andWhere($this->terms->build($qb, $query->terms, 'term', $a->entry));
        if ($query->unread) {
            $this->unread($qb, $a);
        }
    }

    /**
     * @param list<int> $entryIds
     */
    public function applyIds(QueryBuilder $qb, EntryAliases $a, array $entryIds): void
    {
        $qb->andWhere(\sprintf('%s.id IN (:ids)', $a->entry))->setParameter('ids', $entryIds);
    }

    private function applyView(QueryBuilder $qb, EntryAliases $a, string $view): void
    {
        switch ($view) {
            case 'unread':
                $this->unread($qb, $a);
                break;
            case 'favorites':
                $qb->andWhere(\sprintf('%s.isFavorite = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'kept':
                $qb->andWhere(\sprintf('%s.isKept = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'viewed':
                $qb->andWhere(\sprintf('%s.isViewed = :flag', $a->state))
                    ->setParameter('flag', true, Types::BOOLEAN);
                break;
            default:
                break;
        }
    }

    private function unread(QueryBuilder $qb, EntryAliases $a): void
    {
        $qb->andWhere(UnreadDql::predicate($a))->setParameter('notHidden', false, Types::BOOLEAN);
    }
}
