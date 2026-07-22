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
     * Entries in feeds the caller subscribes to, newest first by
     * effectiveDate (publishedAt ?? createdAt) then id, keyset-paginated.
     * LEFT JOINs the caller's EntryState and folds Subscription.markedReadUntil
     * into an effective isRead. `view` narrows to unread/favorites/kept.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $limit = max(1, min($query->limit, EntryQuery::MAX_LIMIT));

        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.feed', 'f')->addSelect('f')
            // Unrelated-entity joins: the caller's subscription to this entry's
            // feed, and the caller's optional per-entry state row.
            ->join(Subscription::class, 's', 'WITH', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'WITH', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isRead AS esRead')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->addSelect('COALESCE(e.publishedAt, e.createdAt) AS HIDDEN effectiveDate')
            ->setParameter('user', $query->userId)
            ->orderBy('effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if ($query->subscriptionId !== null) {
            $qb->andWhere('s.id = :sid')->setParameter('sid', $query->subscriptionId);
        }

        if ($query->tagId !== null) {
            // A tag id matches at most one row of s.tags, so this inner join
            // never duplicates an entry.
            $qb->innerJoin('s.tags', 't', 'WITH', 't.id = :tagId')
                ->setParameter('tagId', $query->tagId);
        }

        $this->applyView($qb, $query->view);
        $this->applyCursor($qb, $query->cursor);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The entry only if the caller subscribes to its feed — the IDOR gate for
     * per-entry state writes. Returns a managed Entry (or null → 404).
     */
    public function findOneSubscribedByUser(int $entryId, int $userId): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'WITH', 's.feed = e.feed AND s.user = :user')
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
                $qb->andWhere(
                    'es.isRead = :readFalse '
                    . 'OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL '
                    . 'OR COALESCE(e.publishedAt, e.createdAt) > s.markedReadUntil))',
                )->setParameter('readFalse', false, Types::BOOLEAN);
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

        $qb->andWhere(
            '(COALESCE(e.publishedAt, e.createdAt) < :curDate '
            . 'OR (COALESCE(e.publishedAt, e.createdAt) = :curDate AND e.id < :curId))',
        )
            ->setParameter('curDate', $cursor->date, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
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
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Effective read state: an explicit EntryState row wins; absent one, the
     * subscription watermark reads everything at or below it.
     *
     * @param array<array-key, mixed> $row
     */
    private function rowIsRead(array $row, Entry $entry): bool
    {
        $esRead = $row['esRead'];
        if ($esRead !== null) {
            return (bool) $esRead;
        }

        $markedReadUntil = $row['markedReadUntil'];
        $effectiveDate = $entry->getPublishedAt() ?? $entry->getCreatedAt();

        return $markedReadUntil instanceof \DateTimeInterface
            && $effectiveDate <= $markedReadUntil;
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
        if (\is_string($customTitle) && $customTitle !== '') {
            return $customTitle;
        }

        $feedTitle = $row['feedTitle'];
        if (\is_string($feedTitle) && $feedTitle !== '') {
            return $feedTitle;
        }

        $feedUrl = $row['feedUrl'];

        return \is_string($feedUrl) ? $feedUrl : '';
    }
}
