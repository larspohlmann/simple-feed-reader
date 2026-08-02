<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
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

    /**
     * The user's subscriptions carrying a given tag (feed eager-loaded). Rooted
     * at Subscription via the entity manager directly — this repository's own
     * createQueryBuilder() roots at SubscriptionTag, which DQL will not let a
     * query select through without also selecting the root — so the two ends of
     * the join↔tag relationship are queried from here on purpose, keeping them
     * beside forTagBySubscriptionId() rather than back on SubscriptionRepository.
     *
     * @return list<Subscription>
     */
    public function findSubscriptionsForUserByTagId(int $userId, int $tagId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('s')->addSelect('f')
            ->from(Subscription::class, 's')
            ->leftJoin('s.feed', 'f')
            ->innerJoin('s.subscriptionTags', 'st')->innerJoin('st.tag', 't')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('t.id = :tagId')->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Subscriptions carrying a given tag — used to detach the tag before it is
     * deleted (portable: does not rely on join-table FK cascade behaviour).
     *
     * @return list<Subscription>
     */
    public function findSubscriptionsByTag(Tag $tag): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Subscription::class, 's')
            ->innerJoin('s.subscriptionTags', 'st')->innerJoin('st.tag', 't')
            ->andWhere('t = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
