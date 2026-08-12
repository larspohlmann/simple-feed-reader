<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Http\EntryCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * @param list<string> $guidHashes
     *
     * @return list<string> the subset of hashes that already exist for this feed
     */
    public function findExistingGuidHashes(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.guidHash')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> the subset of ids that still exist — a caller holding
     *                   ids from an earlier checkpoint uses this to drop the
     *                   ones pruned since
     */
    public function findExistingIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * The feed's existing entries for the given guid hashes, indexed by hash —
     * lets a re-parse match items back to their persisted rows.
     *
     * @param list<string> $guidHashes
     *
     * @return array<string, Entry>
     */
    public function findByFeedIndexedByGuidHash(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<Entry> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getResult();

        $byHash = [];
        foreach ($rows as $entry) {
            $byHash[$entry->getGuidHash()] = $entry;
        }

        return $byHash;
    }

    /**
     * Entries in feeds the caller subscribes to, sorted by refresh run DESC
     * (createdAt = run-start), then article publication time DESC within the
     * run (null publishedAt sorts last), then id DESC as the final tiebreaker.
     * Keyset-paginated on (createdAt, publishedAt, id). LEFT JOINs the caller's
     * EntryState and folds Subscription.markedReadUntil into an effective
     * isRead. `view` narrows to unread/favorites/kept.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $limit = max(1, min($query->limit, EntryQuery::MAX_LIMIT));

        $qb = $this->rowQueryBuilder($query->userId)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.publishedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if ($query->subscriptionId !== null) {
            $qb->andWhere('s.id = :sid')->setParameter('sid', $query->subscriptionId);
        }

        if ($query->tagId !== null) {
            // A tag matches at most one join row per subscription, so this inner
            // join never duplicates an entry. IDENTITY() reads the tag_id FK
            // without a second join to the tag table.
            $qb->innerJoin('s.subscriptionTags', 'st', 'WITH', 'IDENTITY(st.tag) = :tagId')
                ->setParameter('tagId', $query->tagId);
        }

        $this->applyView($qb, $query->view);
        $this->applyCursor($qb, $query->cursor);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The shared "entry list row" projection: the entry plus the caller's
     * subscription, feed, and optional per-entry state. listForUser adds
     * ordering/paging/filters; oneRowForUser adds an id filter.
     */
    private function rowQueryBuilder(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.feed', 'f')->addSelect('f')
            // Unrelated-entity joins: the caller's subscription to this entry's
            // feed, and the caller's optional per-entry state row.
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isRead AS esRead')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->setParameter('user', $userId);
    }

    /**
     * One entry as a list row (entry + subscription + folded state), or null if
     * the caller does not subscribe to its feed — the same IDOR gate as the list.
     * Lets a deep link open an entry the current list page does not contain.
     */
    public function oneRowForUser(int $entryId, int $userId): ?EntryListRow
    {
        /** @var array<array-key, mixed>|null $row */
        $row = $this->rowQueryBuilder($userId)
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : $this->hydrateRow($row);
    }

    /**
     * The entry only if the caller subscribes to its feed — the IDOR gate for
     * per-entry state writes. Returns a managed Entry (or null → 404).
     */
    public function findOneSubscribedByUser(int $entryId, int $userId): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry;
    }

    private function applyView(QueryBuilder $qb, string $view): void
    {
        switch ($view) {
            case 'unread':
                $qb->andWhere(UnreadDql::predicate())->setParameter('readFalse', false, Types::BOOLEAN);
                break;
            case 'favorites':
                $qb->andWhere('es.isFavorite = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'kept':
                $qb->andWhere('es.isKept = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            default:
                // 'all' — no state filter.
                break;
        }
    }

    private function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        // Keyset "before" predicate for (createdAt, publishedAt, id) DESC, with
        // nulls sorting last within a run (SQL DESC default). A null cursor
        // publishedAt is the boundary between the run's publishedAt-bearing
        // rows and its null-publishedAt tail; once past it, only null-publishedAt
        // rows remain, ranked by id DESC.
        if ($cursor->publishedAt === null) {
            $qb->andWhere(
                '(e.createdAt < :curCreatedAt '
                . 'OR (e.createdAt = :curCreatedAt AND e.publishedAt IS NULL AND e.id < :curId))',
            )
                ->setParameter('curCreatedAt', $cursor->createdAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('curId', $cursor->id);
        } else {
            $qb->andWhere(
                '(e.createdAt < :curCreatedAt '
                . 'OR (e.createdAt = :curCreatedAt AND e.publishedAt IS NULL) '
                . 'OR (e.createdAt = :curCreatedAt AND e.publishedAt = :curPublishedAt AND e.id < :curId) '
                . 'OR (e.createdAt = :curCreatedAt AND e.publishedAt < :curPublishedAt))',
            )
                ->setParameter('curCreatedAt', $cursor->createdAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('curPublishedAt', $cursor->publishedAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('curId', $cursor->id);
        }
    }

    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    private function hydrateRow(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        return new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isRead: $this->rowIsRead($row, $entry),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            isViewed: (bool) ($row['esViewed'] ?? false),
            markedReadUntil: $row['markedReadUntil'] instanceof \DateTimeImmutable
                ? $row['markedReadUntil']
                : null,
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowIsRead(array $row, Entry $entry): bool
    {
        $esRead = $row['esRead'];
        $markedReadUntil = $row['markedReadUntil'];

        return EffectiveReadState::isRead(
            $esRead === null ? null : (bool) $esRead,
            $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
            $entry->getEffectiveDate(),
        );
    }

    /**
     * The subscription's display title: its custom override, else the feed
     * title, else the bare feed URL as a last resort.
     *
     * @param array<array-key, mixed> $row
     */
    private function rowTitle(array $row): string
    {
        $customTitle = $row['customTitle'];
        $feedTitle = $row['feedTitle'];
        $feedUrl = $row['feedUrl'];

        return SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            \is_string($feedTitle) ? $feedTitle : null,
            \is_string($feedUrl) ? $feedUrl : '',
        );
    }
}
