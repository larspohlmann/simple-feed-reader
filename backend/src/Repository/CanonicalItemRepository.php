<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CanonicalItem;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function ensure(CanonicalKeyModel $key): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO canonical_item (kind, canonical_key, key_hash, self_reply_count) VALUES (?, ?, ?, 0)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$key->kind->value, $key->key, $key->keyHash()],
        );

        $id = $connection->fetchOne(
            'SELECT id FROM canonical_item WHERE kind = ? AND key_hash = ?',
            [$key->kind->value, $key->keyHash()],
        );

        return is_numeric($id) ? (int) $id : throw new RecordNotFoundException('No canonical item for ' . $key->key);
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
