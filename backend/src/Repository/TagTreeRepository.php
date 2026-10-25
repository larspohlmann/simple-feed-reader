<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagTreeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** Locks the user's row until the transaction ends, so tag placements for one user run one at a time. */
    public function lockTagsOf(int $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->select('u.id')
            ->from(User::class, 'u')
            ->andWhere('u.id = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();
    }

    /**
     * Read from the rows, not the loaded entities, which a placement committed meanwhile would leave stale.
     *
     * @return array<int, int|null> parent id by tag id
     */
    public function parentIdsForUser(int $userId): array
    {
        /** @var list<array{id: int|string, parentId: int|string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id', 'IDENTITY(t.parent) AS parentId')
            ->from(Tag::class, 't')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getScalarResult();

        $parentIds = [];
        foreach ($rows as $row) {
            $parentIds[(int) $row['id']] = $row['parentId'] === null ? null : (int) $row['parentId'];
        }

        return $parentIds;
    }
}
