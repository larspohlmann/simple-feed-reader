<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SavedSearchNetworkInclusion;
use App\Enum\EntryView;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * The entry-list scope filters, applied to a given EntryAliases set so the
 * list, its duplicate-collapse semi-join and the unread counts share one definition.
 * Holds no query state; the caller passes the aliases and the query.
 */
final readonly class EntryScopePredicates
{
    public function __construct(private SearchTermsPredicateBuilder $terms)
    {
    }

    /** Leaves out the feed group: the list resolves it to subscription ids first, see inSubscriptions(). */
    public function applyList(QueryBuilder $qb, EntryAliases $aliases, EntryQuery $query): void
    {
        if ($query->subscriptionId !== null) {
            $qb->andWhere($aliases->subscriptionKey() . ' = :sid')->setParameter('sid', $query->subscriptionId);
        }
        if ($query->tagId !== null) {
            TagScopeDql::restrict($qb, $aliases->subscription, $query->tagId);
        }
        if ($query->networkScope !== null) {
            $this->joinNetworkFeeds($qb, $aliases);
            NetworkScopeDql::restrict($qb, $aliases->networkFeed, $query->networkScope);
        }
        if ($query->hidesExcludedFeeds()) {
            $this->inAllItems($qb, $aliases);
        }
        $this->applyView($qb, $aliases, $query->view);
    }

    /** One row per tag a subscription carries, to group by the tag. */
    public function joinTags(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->innerJoin(\sprintf('%s.subscriptionTags', $aliases->subscription), $aliases->tag);
    }

    public function joinNetworkFeeds(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->innerJoin(\sprintf('%s.feed', $aliases->entry), $aliases->networkFeed)
            ->andWhere($aliases->networkKey() . ' IS NOT NULL');
    }

    /**
     * @param non-empty-list<int> $subscriptionIds
     */
    public function inSubscriptions(QueryBuilder $qb, EntryAliases $aliases, array $subscriptionIds): void
    {
        $qb->andWhere($aliases->subscriptionKey() . ' IN (:subscriptionIds)')
            ->setParameter('subscriptionIds', $subscriptionIds);
    }

    public function inAllItems(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(\sprintf('%s.includeInAllItems = true', $aliases->subscription));
    }

    /** Binds only the shared :user, which the duplicate-collapse subquery relies on. */
    public function inSavedSearches(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $feed = 'searchFeed' . ucfirst($aliases->entry);
        $inclusion = 'networkInclusion' . ucfirst($aliases->entry);

        $qb->andWhere(\sprintf(
            '%1$s.includeInSavedSearches = true AND EXISTS (SELECT 1 FROM %2$s %3$s WHERE %3$s = %4$s.feed AND '
            . '(%3$s.network IS NULL OR EXISTS (SELECT 1 FROM %5$s %6$s WHERE %6$s.user = :user '
            . 'AND %6$s.network = %3$s.network)))',
            $aliases->subscription,
            Feed::class,
            $feed,
            $aliases->entry,
            SavedSearchNetworkInclusion::class,
            $inclusion,
        ));
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

    public function unread(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(UnreadDql::predicate($aliases))->setParameter('notHidden', false, Types::BOOLEAN);
    }
}
