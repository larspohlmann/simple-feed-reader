<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use App\Enum\FeedLanguage;
use App\Enum\InclusionSurface;
use App\Service\Curation\YourStream\Model\RarityWindowModel;
use App\Service\Curation\YourStream\Model\StreamCandidateModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** What Your stream reads about the feeds an account has switched into Your stream. */
final readonly class YourStreamSourceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<FeedLanguage> in FeedLanguage::cases() order */
    public function findFeedLanguages(int $userId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT feed.traits.language AS language')
            ->from(Subscription::class, 'subscription')
            ->join('subscription.feed', 'feed')
            ->where('IDENTITY(subscription.user) = :user')
            ->andWhere('feed.traits.language IS NOT NULL')
            ->setParameter('user', $userId);
        InclusionScopeDql::restrict($qb, 'subscription', InclusionSurface::YourStream);

        /** @var list<array{language: FeedLanguage}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $declared = array_column($rows, 'language');

        return array_values(array_filter(
            FeedLanguage::cases(),
            static fn (FeedLanguage $language): bool => \in_array($language, $declared, true),
        ));
    }

    public function findFirstEntryIdSince(\DateTimeImmutable $since): ?int
    {
        $lowest = $this->entityManager->createQueryBuilder()
            ->select('MIN(entry.id)')
            ->from(Entry::class, 'entry')
            ->where('entry.dates.effectiveDate >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $lowest ? null : (int) $lowest;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<StreamEntryModel> in the order of $entryIds, those that exist
     */
    public function findEntries(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{id: int, entryLanguage: ?FeedLanguage, feedLanguage: ?FeedLanguage, title: string, summary: ?string}> $rows */
        $rows = $this->selectEntries()
            ->where('entry.id IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();
        $entriesById = [];
        foreach ($rows as $row) {
            $entriesById[$row['id']] = self::buildEntry($row);
        }

        return array_values(array_filter(array_map(
            static fn (int $entryId): ?StreamEntryModel => $entriesById[$entryId] ?? null,
            $entryIds,
        )));
    }

    /** @return list<StreamEntryModel> descending by id below $beforeId, from the account's Your stream feeds */
    public function findNewestEntries(int $userId, RarityWindowModel $window, int $beforeId, int $limit): array
    {
        $qb = $this->selectEntries()
            ->join(Subscription::class, 'subscription', 'ON', 'subscription.feed = feed')
            ->where('IDENTITY(subscription.user) = :user')
            ->andWhere('entry.dates.effectiveDate >= :since')
            ->andWhere('entry.id > :afterId')
            ->andWhere('entry.id < :beforeId')
            ->orderBy('entry.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('user', $userId)
            ->setParameter('since', $window->since)
            ->setParameter('afterId', $window->afterId)
            ->setParameter('beforeId', $beforeId);
        InclusionScopeDql::restrict($qb, 'subscription', InclusionSurface::YourStream);

        /** @var list<array{id: int, entryLanguage: ?FeedLanguage, feedLanguage: ?FeedLanguage, title: string, summary: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(self::buildEntry(...), $rows);
    }

    /**
     * @param list<int>           $userIds
     * @param non-empty-list<int> $entryIds
     *
     * @return list<StreamCandidateModel> each entry once, ascending by id, with the accounts of $userIds whose
     *                                    Your stream subscriptions carry its feed
     */
    public function findCandidates(array $userIds, array $entryIds, \DateTimeImmutable $publishedSince): array
    {
        $userIdsByFeed = $this->findUserIdsByFeed($userIds);
        if ([] === $userIdsByFeed) {
            return [];
        }

        $qb = $this->selectEntries()
            ->addSelect('IDENTITY(entry.feed) AS feedId')
            ->where('entry.id IN (:entryIds)')
            ->andWhere('IDENTITY(entry.feed) IN (:feeds)')
            ->andWhere('entry.dates.effectiveDate >= :since')
            ->orderBy('entry.id', 'ASC')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('feeds', array_keys($userIdsByFeed))
            ->setParameter('since', $publishedSince);

        /** @var list<array{id: int, feedId: int|string, entryLanguage: ?FeedLanguage, feedLanguage: ?FeedLanguage, title: string, summary: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): StreamCandidateModel => new StreamCandidateModel(
                self::buildEntry($row),
                $userIdsByFeed[(int) $row['feedId']],
            ),
            $rows,
        );
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, non-empty-list<int>> feed id => the accounts whose Your stream subscriptions carry it
     */
    private function findUserIdsByFeed(array $userIds): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(subscription.user) AS userId', 'IDENTITY(subscription.feed) AS feedId')
            ->from(Subscription::class, 'subscription')
            ->where('IDENTITY(subscription.user) IN (:users)')
            ->orderBy('subscription.id', 'ASC')
            ->setParameter('users', $userIds);
        InclusionScopeDql::restrict($qb, 'subscription', InclusionSurface::YourStream);

        /** @var list<array{userId: int|string, feedId: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $userIdsByFeed = [];
        foreach ($rows as $row) {
            $userId = (int) $row['userId'];
            $userIdsByFeed[(int) $row['feedId']][$userId] = $userId;
        }

        return array_map(array_values(...), $userIdsByFeed);
    }

    private function selectEntries(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'entry.id AS id',
                'entry.text.language AS entryLanguage',
                'feed.traits.language AS feedLanguage',
                'entry.headline.title AS title',
                \sprintf('SUBSTRING(entry.text.summary, 1, %d) AS summary', StreamEntryModel::SUMMARY_CHARACTERS),
            )
            ->from(Entry::class, 'entry')
            ->join('entry.feed', 'feed');
    }

    /** @param array{id: int, entryLanguage: ?FeedLanguage, feedLanguage: ?FeedLanguage, title: string, summary: ?string} $row */
    private static function buildEntry(array $row): StreamEntryModel
    {
        return new StreamEntryModel(
            $row['id'],
            $row['entryLanguage'] ?? $row['feedLanguage'],
            $row['title'],
            $row['summary'],
        );
    }
}
