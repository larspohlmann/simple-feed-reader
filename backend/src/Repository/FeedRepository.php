<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Enum\FeedStatus;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Backup\RestoreFeeds\RestoreFeedsInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feed>
 */
final class FeedRepository extends ServiceEntityRepository implements RestoreFeedsInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feed::class);
    }

    /**
     * Feeds eligible for refresh, never-fetched first, then most overdue.
     *
     * @return list<Feed>
     */
    public function findDue(DueFeedCriteria $criteria, int $limit): array
    {
        /** @var list<Feed> $feeds */
        $feeds = $this->dueQueryBuilder($criteria)
            ->addSelect('COALESCE(f.fetchSchedule.nextFetchAt, :epoch) AS HIDDEN dueOrder')
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('dueOrder', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $feeds;
    }

    public function countDue(DueFeedCriteria $criteria): int
    {
        return (int) $this->dueQueryBuilder($criteria)
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The URLs of the feeds this user is subscribed to, as a lookup set. The
     * catalog marks its entries `subscribed` by URL rather than by feed id
     * because a catalog row knows a URL, not which shared Feed row it became.
     *
     * @return array<string, true>
     */
    public function subscribedUrlSetForUser(int $userId): array
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.url AS url')
            ->join(Subscription::class, 's', 'ON', 's.feed = f AND s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['url']] = true;
        }

        return $set;
    }

    /**
     * The feed rows behind a set of urls, indexed by url: one query for the whole set a restore file declares. A url
     * with no row is absent.
     *
     * @param list<string> $urls
     *
     * @return array<string, Feed>
     */
    public function findByUrlsIndexedByUrl(array $urls): array
    {
        if ([] === $urls) {
            return [];
        }

        /** @var list<Feed> $feeds */
        $feeds = $this->createQueryBuilder('f')
            ->andWhere('f.url IN (:urls)')
            ->setParameter('urls', $urls)
            ->getQuery()
            ->getResult();

        $byUrl = [];
        foreach ($feeds as $feed) {
            $byUrl[$feed->getUrl()] = $feed;
        }

        return $byUrl;
    }

    /**
     * The ids of the feeds this user subscribes to. Read before deleting the
     * account: once the subscription rows cascade away there is nothing left
     * to ask.
     *
     * @return list<int>
     */
    public function idsSubscribedByUser(int $userId): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.id AS id')
            ->join(Subscription::class, 's', 'ON', 's.feed = f AND s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Whether any other account subscribes. A restore asks before writing: another reader's unread list must not grow,
     * so it references the row and adds nothing. A yes/no on purpose: no caller has business with how many strangers.
     */
    public function isReadByAnotherUser(int $feedId, int $excludedUserId): bool
    {
        $others = (int) $this->createQueryBuilder('f')
            ->select('COUNT(s.id)')
            ->join(Subscription::class, 's', 'ON', 's.feed = f')
            ->andWhere('f.id = :feedId')->setParameter('feedId', $feedId)
            ->andWhere('s.user <> :userId')->setParameter('userId', $excludedUserId)
            ->getQuery()
            ->getSingleScalarResult();

        return $others > 0;
    }

    /**
     * The subset of the given urls this user subscribes to, keyed by url.
     *
     * @param list<string> $urls
     *
     * @return array<string, int>
     */
    public function idsByUrlsForUser(int $userId, array $urls): array
    {
        if ([] === $urls) {
            return [];
        }

        /** @var list<array{url: string, id: int}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.url AS url', 'f.id AS id')
            ->join(Subscription::class, 's', 'ON', 's.feed = f AND s.user = :userId')
            ->andWhere('f.url IN (:urls)')
            ->setParameter('userId', $userId)
            ->setParameter('urls', $urls)
            ->getQuery()
            ->getArrayResult();

        $idsByUrl = [];
        foreach ($rows as $row) {
            $idsByUrl[$row['url']] = $row['id'];
        }

        return $idsByUrl;
    }

    /**
     * Subscribed Bluesky profile and author feeds whose picture was never asked for or not since $checkedBefore,
     * never-asked first.
     *
     * @return list<Feed>
     */
    public function findBlueskyProfileFeedsWithImageDue(\DateTimeImmutable $checkedBefore, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere(sprintf('EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)', Subscription::class))
            ->andWhere('f.imageCheckedAt IS NULL OR f.imageCheckedAt < :checkedBefore')
            ->setParameter('checkedBefore', $checkedBefore)
            ->orderBy('f.imageCheckedAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit);
        NetworkScopeDql::restrict($qb, 'f', NetworkScope::profiles(SocialNetwork::Bluesky));

        /** @var list<Feed> $feeds */
        $feeds = $qb->getQuery()->getResult();

        return $feeds;
    }

    private function dueQueryBuilder(DueFeedCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('f');

        $this->restrictToFetchable($qb);
        $this->restrictToSchedule($qb, $criteria);
        $this->restrictToOwnership($qb, $criteria);
        $this->restrictToNetwork($qb, $criteria);
        $this->restrictToFeedGroup($qb, $criteria);
        $this->restrictToUnhandled($qb, $criteria);
        $this->restrictToConnectedAccounts($qb);

        return $qb;
    }

    private function restrictToFetchable(QueryBuilder $qb): void
    {
        SavedFeedDql::exclude($qb, 'f');
    }

    private function restrictToSchedule(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->feedId !== null) {
            // Manual per-feed retry: exactly this feed, "gone" included, schedule
            // ignored — but the ownership scope still applies.
            $qb->andWhere('f.id = :feedId')->setParameter('feedId', $criteria->feedId);

            return;
        }

        $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

        if ($criteria->force) {
            // Force drops the schedule entirely; only the cooldown may hold a
            // feed back, and only when the caller set one.
            $this->restrictToCooldown($qb, $criteria);

            return;
        }

        $qb->andWhere('(f.fetchSchedule.nextFetchAt IS NULL OR f.fetchSchedule.nextFetchAt <= :now)')
            ->setParameter('now', $criteria->now);
    }

    private function restrictToCooldown(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->cooldownCutoff === null) {
            return;
        }

        // A future lastFetchedAt is a clock fault (a non-UTC worker wrote them in #151): treat it as stale, or it reads
        // as "just fetched" and the feed never refreshes again.
        $qb->andWhere(
            '(f.fetchSchedule.lastFetchedAt IS NULL'
            . ' OR f.fetchSchedule.lastFetchedAt <= :cooldownCutoff'
            . ' OR f.fetchSchedule.lastFetchedAt > :now)',
        )
            ->setParameter('cooldownCutoff', $criteria->cooldownCutoff)
            ->setParameter('now', $criteria->now);
    }

    private function restrictToOwnership(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $criteria->userId);
        }

        if ($criteria->tagId === null) {
            return;
        }

        // Scope to feeds the user has tagged with $tagId or a child of it. Pinning
        // the subscription to :userId means another user's identically-named tag
        // can never widen the scope.
        $qb->andWhere(sprintf(
            'EXISTS (SELECT ts.id FROM %s ts WHERE ts.feed = f AND ts.user = :userId AND %s)',
            Subscription::class,
            TagScopeDql::predicate('ts'),
        ))->setParameter('tagId', $criteria->tagId);
    }

    private function restrictToNetwork(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->networkScope === null) {
            return;
        }

        NetworkScopeDql::restrict($qb, 'f', $criteria->networkScope);
    }

    private function restrictToFeedGroup(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->feedGroup === null) {
            return;
        }

        $subscriptions = $this->getEntityManager()->createQueryBuilder()
            ->select('gs.id')
            ->from(Subscription::class, 'gs')
            ->andWhere('gs.feed = f')
            ->andWhere('gs.user = :userId');
        FeedGroupDql::restrict($subscriptions, 'gs', 'f', $criteria->feedGroup);

        $qb->andWhere(sprintf('EXISTS (%s)', $subscriptions->getDQL()))->setParameter('userId', $criteria->userId);
    }

    private function restrictToUnhandled(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->excludedFeedIds === []) {
            return;
        }

        $qb->andWhere('f.id NOT IN (:excludedFeedIds)')
            ->setParameter('excludedFeedIds', $criteria->excludedFeedIds);
    }

    /** A private feed of an account whose credential was refused waits for the reconnect, which makes it due again. */
    private function restrictToConnectedAccounts(QueryBuilder $qb): void
    {
        $qb->andWhere(sprintf(
            'f.sourceFormat NOT IN (:socialFormats) OR NOT EXISTS (SELECT sf.id FROM %s sf JOIN sf.socialAccount sa'
            . ' WHERE sf.feed = f AND sa.status = :needsReconnect)',
            SocialFeed::class,
        ))
            ->setParameter('socialFormats', SourceFormat::SOCIAL)
            ->setParameter('needsReconnect', SocialAccountStatus::NeedsReconnect);
    }
}
