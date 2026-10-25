<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagTreeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * The user's tag tree as committed, read with a write lock: a concurrent placement waits for this
     * transaction, and loaded entities cannot hide its changes. Call it inside a transaction.
     *
     * @return array<int, int|null> parent id by tag id
     */
    public function lockParentIdsForUser(int $userId): array
    {
        /** @var list<array{id: int|string, parentId: int|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id', 'IDENTITY(t.parent) AS parentId')
            ->from(Tag::class, 't')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();

        $parentIds = [];
        foreach ($rows as $row) {
            $parentIds[(int) $row['id']] = $row['parentId'] === null ? null : (int) $row['parentId'];
        }

        return $parentIds;
    }
}
