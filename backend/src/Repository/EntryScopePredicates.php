<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SavedSearchNetworkInclusion;
use App\Enum\EntryView;
use App\Enum\InclusionSurface;
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
            InclusionScopeDql::restrict($qb, $aliases->subscription, InclusionSurface::AllItems);
        }
        $this->applyView($qb, $aliases, $query->view);
    }

    private function joinNetworkFeeds(QueryBuilder $qb, EntryAliases $aliases): void
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

    public function inSavedSearches(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $feed = 'searchFeed' . ucfirst($aliases->entry);
        $inclusion = 'networkInclusion' . ucfirst($aliases->entry);

        InclusionScopeDql::restrict($qb, $aliases->subscription, InclusionSurface::SavedSearches);
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s = %3$s.feed AND '
            . '(%2$s.network IS NULL OR EXISTS (SELECT 1 FROM %4$s %5$s WHERE %5$s.user = :user '
            . 'AND %5$s.network = %2$s.network)))',
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
            case EntryView::Shared:
                $qb->andWhere($aliases->share . '.sharedAt IS NOT NULL');
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
