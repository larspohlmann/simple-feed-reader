<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryLemmyPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryLemmyPost> */
final class EntryLemmyPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryLemmyPost::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, EntryLemmyPost> keyed by entry id
     */
    public function byEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<EntryLemmyPost> $rows */
        $rows = $this->createQueryBuilder('post')
            ->where('IDENTITY(post.entry) IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $byEntryId = [];
        foreach ($rows as $row) {
            $byEntryId[$row->getEntry()->requireId()] = $row;
        }

        return $byEntryId;
    }
}
