<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Repository\SubscriptionDisplayTitle;
use App\Service\Text\PlainText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Loads the reader's weighted history for the recommendation prompt: three
 * capped, newest-first sections, each entry counted in only its highest one
 * (favorites beat kept, kept beats viewed). Only entries whose feed the
 * reader still subscribes to are considered — an unsubscribed feed's history
 * is gone by design.
 *
 * Favorites and kept sections order by the entry's effectiveDate, because
 * EntryState has no favorited-at timestamp to order by instead; the viewed
 * section orders by the state row's own viewedAt.
 */
final readonly class RecommendationHistoryLoader
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function load(int $userId, EffectiveRecommendationSettings $settings): RecommendationHistory
    {
        return new RecommendationHistory(
            favorites: $this->loadFavorites($userId, $settings->favoritesCap),
            kept: $this->loadKept($userId, $settings->keptCap),
            viewed: $this->loadViewed($userId, $settings->viewedCap),
        );
    }

    /**
     * @return list<PromptLine>
     */
    private function loadFavorites(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isFavorite = :true')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN);

        return $this->linesFor($qb);
    }

    /**
     * @return list<PromptLine>
     */
    private function loadKept(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isKept = :true AND es.isFavorite = :false')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->linesFor($qb);
    }

    /**
     * @return list<PromptLine>
     */
    private function loadViewed(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isViewed = :true AND es.isFavorite = :false AND es.isKept = :false')
            ->orderBy('es.viewedAt', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->linesFor($qb);
    }

    private function historyQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('es', 'e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->from(EntryState::class, 'es')
            ->join('es.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->setParameter('user', $userId);
    }

    /**
     * @return list<PromptLine>
     */
    private function linesFor(QueryBuilder $qb): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): PromptLine => $this->hydrateLine($row), $rows);
    }

    /**
     * The joined 'e' and 'f' selects exist to eagerly fetch the entry and feed
     * in the same query rather than lazy-loading them per row; because both
     * are reachable via a to-one association from the root 'es', Doctrine
     * folds them into the graph instead of giving them their own row index —
     * only the EntryState root and the scalar customTitle appear as row keys.
     *
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => EntryState, customTitle: ?string]
     */
    private function hydrateLine(array $row): PromptLine
    {
        /** @var EntryState $state */
        $state = $row[0];
        $entry = $state->getEntry();
        $feed = $entry->getFeed();
        $customTitle = $row['customTitle'];
        $feedName = SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            $feed->getTitle(),
            $feed->getUrl(),
        );

        return new PromptLine(
            entryId: null,
            title: $entry->getTitle(),
            feedName: $feedName,
            date: $entry->getEffectiveDate()->format('Y-m-d'),
            description: PlainText::from($entry->getSummary() ?? $entry->getContentHtml()),
        );
    }
}
