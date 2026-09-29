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

    public function applyList(QueryBuilder $qb, EntryAliases $aliases, EntryQuery $query): void
    {
        if ($query->subscriptionId !== null) {
            $qb->andWhere(\sprintf('%s.id = :sid', $aliases->subscription))
                ->setParameter('sid', $query->subscriptionId);
        }
        if ($query->tagId !== null) {
            // A tag matches at most one join row per subscription, so this inner
            // join never duplicates an entry. IDENTITY() reads the tag_id FK
            // without a second join to the tag table.
            $qb->innerJoin(
                \sprintf('%s.subscriptionTags', $aliases->subscription),
                $aliases->tag,
                'WITH',
                \sprintf('IDENTITY(%s.tag) = :tagId', $aliases->tag),
            )->setParameter('tagId', $query->tagId);
        }
        if ($query->hidesExcludedFeeds()) {
            $qb->andWhere(\sprintf('%s.includeInAllItems = true', $aliases->subscription));
        }
        $this->applyView($qb, $aliases, $query->view);
    }

    public function applySearch(QueryBuilder $qb, EntryAliases $aliases, EntrySearchQuery $query): void
    {
        $qb->andWhere($this->terms->build($qb, $query->terms, 'term', $aliases->entry));
        if ($query->unread) {
            $this->unread($qb, $aliases);
        }
    }

    /**
     * @param list<int> $entryIds
     */
    public function applyIds(QueryBuilder $qb, EntryAliases $aliases, array $entryIds): void
    {
        $qb->andWhere(\sprintf('%s.id IN (:ids)', $aliases->entry))->setParameter('ids', $entryIds);
    }

    private function applyView(QueryBuilder $qb, EntryAliases $aliases, EntryView $view): void
    {
        switch ($view) {
            case EntryView::Unread:
                $this->unread($qb, $aliases);
                break;
            case EntryView::Favorites:
                $this->stateFlagIsSet($qb, $aliases, 'isFavorite');
                break;
            case EntryView::Kept:
                $this->stateFlagIsSet($qb, $aliases, 'isKept');
                break;
            case EntryView::Viewed:
                $this->stateFlagIsSet($qb, $aliases, 'isViewed');
                break;
            default:
                break;
        }
    }

    private function stateFlagIsSet(QueryBuilder $qb, EntryAliases $aliases, string $flag): void
    {
        $qb->andWhere(\sprintf('%s.%s = :flag', $aliases->state, $flag))->setParameter('flag', true, Types::BOOLEAN);
    }

    private function unread(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(UnreadDql::predicate($aliases))->setParameter('notHidden', false, Types::BOOLEAN);
    }
}
