<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LemmyPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LemmyPost> */
final class LemmyPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LemmyPost::class);
    }

    /**
     * @param list<int> $canonicalItemIds
     *
     * @return array<int, LemmyPost> keyed by canonical item id
     */
    public function byCanonicalItemIds(array $canonicalItemIds): array
    {
        if ($canonicalItemIds === []) {
            return [];
        }

        /** @var list<LemmyPost> $rows */
        $rows = $this->createQueryBuilder('post')
            ->where('IDENTITY(post.canonicalItem) IN (:canonicalItemIds)')
            ->setParameter('canonicalItemIds', $canonicalItemIds)
            ->getQuery()
            ->getResult();

        $byItemId = [];
        foreach ($rows as $row) {
            $byItemId[$row->getCanonicalItem()->requireId()] = $row;
        }

        return $byItemId;
    }
}
