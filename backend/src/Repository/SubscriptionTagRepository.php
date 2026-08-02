<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionTag>
 */
class SubscriptionTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionTag::class);
    }

    /**
     * The next append position for a feed newly added to this tag: one past the
     * tag's current max (0 when the tag has no feeds yet).
     */
    public function nextPositionForTag(Tag $tag): int
    {
        $max = $this->createQueryBuilder('st')
            ->select('MAX(st.position)')
            ->andWhere('st.tag = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /**
     * The tag's join rows keyed by subscription id — used to reassign positions
     * when the feed order within a tag is changed.
     *
     * @return array<int, SubscriptionTag>
     */
    public function forTagBySubscriptionId(Tag $tag): array
    {
        /** @var list<SubscriptionTag> $rows */
        $rows = $this->createQueryBuilder('st')
            ->andWhere('st.tag = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->getSubscription()->getId()] = $row;
        }

        return $byId;
    }
}
