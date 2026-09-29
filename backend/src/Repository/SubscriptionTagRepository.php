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
final class SubscriptionTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly NextPosition $nextPosition)
    {
        parent::__construct($registry, SubscriptionTag::class);
    }

    public function nextPositionForTag(Tag $tag): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('st')->andWhere('st.tag = :tag')->setParameter('tag', $tag),
        );
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
            $byId[$row->getSubscription()->requireId()] = $row;
        }

        return $byId;
    }
}
