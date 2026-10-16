<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionFeedUrls
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array{id: int, customTitle: ?string, feedUrl: string, feedTitle: ?string, feedImageUrl: ?string}> creation order */
    public function forUser(int $userId): array
    {
        /** @var list<array{id: int, customTitle: ?string, feedUrl: string, feedTitle: ?string, feedImageUrl: ?string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id', 's.customTitle', 'f.url AS feedUrl', 'f.title AS feedTitle', 'f.imageUrl AS feedImageUrl')
            ->from(Subscription::class, 's')
            ->join('s.feed', 'f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
