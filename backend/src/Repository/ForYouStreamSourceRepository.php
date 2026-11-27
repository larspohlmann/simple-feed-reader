<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Enum\FeedLanguage;
use App\Enum\InclusionSurface;
use Doctrine\ORM\EntityManagerInterface;

/** What the For you stream reads about the feeds an account has switched into For you. */
final readonly class ForYouStreamSourceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<FeedLanguage> in FeedLanguage::cases() order */
    public function findFeedLanguages(int $userId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT feed.traits.language AS language')
            ->from(Subscription::class, 'subscription')
            ->join('subscription.feed', 'feed')
            ->where('IDENTITY(subscription.user) = :user')
            ->andWhere('feed.traits.language IS NOT NULL')
            ->setParameter('user', $userId);
        InclusionScopeDql::restrict($qb, 'subscription', InclusionSurface::ForYou);

        /** @var list<array{language: FeedLanguage}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $declared = array_column($rows, 'language');

        return array_values(array_filter(
            FeedLanguage::cases(),
            static fn (FeedLanguage $language): bool => \in_array($language, $declared, true),
        ));
    }
}
