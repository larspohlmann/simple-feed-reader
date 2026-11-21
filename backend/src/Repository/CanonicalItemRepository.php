<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\Subscription;
use App\Enum\CanonicalItemKind;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CanonicalItem>
 */
final class CanonicalItemRepository extends ServiceEntityRepository
{
    private const int KEYS_PER_STATEMENT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CanonicalItem::class);
    }

    /**
     * The item of each key, inserting the rows that are missing; another writer storing one first raises no violation.
     *
     * @param list<CanonicalKeyModel> $keys
     *
     * @return array<string, CanonicalItem> one per distinct key, by `CanonicalKeyModel::identity()`
     */
    public function ensureAll(array $keys, \DateTimeImmutable $createdAt): array
    {
        $distinct = [];
        foreach ($keys as $key) {
            $distinct[$key->identity()] = $key;
        }
        $items = $this->findByKeys($distinct);
        $missing = array_diff_key($distinct, $items);
        if ($missing === []) {
            return $items;
        }

        $this->insertMissing(array_values($missing), $createdAt);
        $items += $this->findByKeys($missing);
        $unstored = array_diff_key($missing, $items);
        if ($unstored !== []) {
            throw new RecordNotFoundException('No canonical item for ' . array_values($unstored)[0]->key);
        }

        return $items;
    }

    /**
     * @param array<string, CanonicalKeyModel> $keys
     *
     * @return array<string, CanonicalItem> by `CanonicalKeyModel::identity()`
     */
    private function findByKeys(array $keys): array
    {
        $found = [];
        foreach (array_chunk(array_values($keys), self::KEYS_PER_STATEMENT) as $chunk) {
            $queryBuilder = $this->createQueryBuilder('item');
            foreach ($chunk as $position => $key) {
                $queryBuilder->orWhere(sprintf('(item.kind = :kind%1$d AND item.keyHash = :hash%1$d)', $position))
                    ->setParameter('kind' . $position, $key->kind)
                    ->setParameter('hash' . $position, $key->keyHash());
            }
            /** @var list<CanonicalItem> $items */
            $items = $queryBuilder->getQuery()->getResult();
            foreach ($items as $item) {
                $found[new CanonicalKeyModel($item->getKind(), $item->getCanonicalKey())->identity()] = $item;
            }
        }

        return $found;
    }

    /** @param list<CanonicalKeyModel> $keys */
    private function insertMissing(array $keys, \DateTimeImmutable $createdAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (array_chunk($keys, self::KEYS_PER_STATEMENT) as $chunk) {
            $parameters = [];
            foreach ($chunk as $key) {
                array_push($parameters, $key->kind->value, $key->key, $key->keyHash(), $createdAt);
            }

            $connection->executeStatement(
                sprintf(
                    'INSERT %s INTO canonical_item (kind, canonical_key, key_hash, self_reply_count, created_at)'
                        . ' VALUES %s',
                    DatabasePlatform::insertIgnoreClause($connection),
                    implode(', ', array_fill(0, count($chunk), '(?, ?, ?, 0, ?)')),
                ),
                $parameters,
                array_merge(...array_fill(
                    0,
                    count($chunk),
                    [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
                )),
            );
        }
    }

    /**
     * Rows that reference an item by foreign key go with it, by cascade. A younger item is spared, as an
     * ingest may have ensured it and not yet flushed the entries that link to it.
     */
    public function deleteUnreferenced(\DateTimeImmutable $createdBefore): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM canonical_item WHERE created_at < ? AND NOT EXISTS '
            . '(SELECT 1 FROM entry WHERE entry.canonical_item_id = canonical_item.id)',
            [$createdBefore],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, CanonicalItem> by id
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<CanonicalItem> $items */
        $items = $this->createQueryBuilder('item')
            ->andWhere('item.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($items as $item) {
            $byId[$item->requireId()] = $item;
        }

        return $byId;
    }

    /**
     * @param list<string> $keyHashes
     *
     * @return array<string, int> the lowest id of an entry the user can read, by key hash
     */
    public function findReadableEntryIds(int $userId, CanonicalItemKind $kind, array $keyHashes): array
    {
        if ($keyHashes === []) {
            return [];
        }
        /** @var list<array{keyHash: string, entryId: int|string}> $rows */
        $rows = $this->createQueryBuilder('item')
            ->select('item.keyHash AS keyHash', 'MIN(entry.id) AS entryId')
            ->join(Entry::class, 'entry', 'WITH', 'entry.canonicalItem = item')
            ->join(
                Subscription::class,
                'subscription',
                'WITH',
                'subscription.feed = entry.feed AND subscription.user = :user',
            )
            ->andWhere('item.kind = :kind')
            ->andWhere('item.keyHash IN (:hashes)')
            ->groupBy('item.keyHash')
            ->setParameter('user', $userId)
            ->setParameter('kind', $kind)
            ->setParameter('hashes', $keyHashes)
            ->getQuery()
            ->getResult();

        $entryIds = [];
        foreach ($rows as $row) {
            $entryIds[$row['keyHash']] = (int) $row['entryId'];
        }

        return $entryIds;
    }
}
