<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/** Feeds nobody subscribes to; entries and read state follow through the FK cascade. */
final readonly class OrphanedFeedRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<int> */
    public function orphanIds(): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT f.id FROM %s f WHERE %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))->getSingleColumnResult();

        return $feedIds;
    }

    /**
     * Re-checks "no subscriber" inside the DELETE: a subscription racing in would otherwise die by the cascade.
     *
     * @param list<int> $feedIds
     */
    public function deleteOrphansAmong(array $feedIds): int
    {
        $affected = $this->em->createQuery(sprintf(
            'DELETE FROM %s f WHERE f.id IN (:feedIds) AND %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))
            ->setParameter('feedIds', $feedIds)
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }

    private function hasNoSubscriberDql(): string
    {
        return sprintf(
            'NOT EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)',
            Subscription::class,
        );
    }
}
