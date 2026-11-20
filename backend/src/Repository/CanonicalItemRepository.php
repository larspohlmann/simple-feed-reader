<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CanonicalItem;
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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CanonicalItem::class);
    }

    /** The item's id, inserting its row when missing; another writer storing the key first raises no violation. */
    public function ensure(CanonicalKeyModel $key, \DateTimeImmutable $createdAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO canonical_item (kind, canonical_key, key_hash, self_reply_count, created_at) '
                . 'VALUES (?, ?, ?, 0, ?)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$key->kind->value, $key->key, $key->keyHash(), $createdAt],
            [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
        );

        $id = $connection->fetchOne(
            'SELECT id FROM canonical_item WHERE kind = ? AND key_hash = ?',
            [$key->kind->value, $key->keyHash()],
        );

        return is_numeric($id) ? (int) $id : throw new RecordNotFoundException('No canonical item for ' . $key->key);
    }

    /**
     * Its votes, likes and Lemmy post row go with it: their foreign keys cascade. A younger item is spared, as an
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
}
