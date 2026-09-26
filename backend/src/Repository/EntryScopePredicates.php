<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EntryView;
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
            // A tag matches at most one join row per subscription, so this inner
            // join never duplicates an entry. IDENTITY() reads the tag_id FK
            // without a second join to the tag table.
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

    private function applyView(QueryBuilder $qb, EntryAliases $a, EntryView $view): void
    {
        switch ($view) {
            case EntryView::Unread:
                $this->unread($qb, $a);
                break;
            case EntryView::Favorites:
                $this->stateFlagIsSet($qb, $a, 'isFavorite');
                break;
            case EntryView::Kept:
                $this->stateFlagIsSet($qb, $a, 'isKept');
                break;
            case EntryView::Viewed:
                $this->stateFlagIsSet($qb, $a, 'isViewed');
                break;
            default:
                break;
        }
    }

    private function stateFlagIsSet(QueryBuilder $qb, EntryAliases $a, string $flag): void
    {
        $qb->andWhere(\sprintf('%s.%s = :flag', $a->state, $flag))->setParameter('flag', true, Types::BOOLEAN);
    }

    private function unread(QueryBuilder $qb, EntryAliases $a): void
    {
        $qb->andWhere(UnreadDql::predicate($a))->setParameter('notHidden', false, Types::BOOLEAN);
    }
}
