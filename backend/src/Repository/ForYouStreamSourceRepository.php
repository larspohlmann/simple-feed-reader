<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use App\Enum\FeedLanguage;
use App\Enum\InclusionSurface;
use App\Service\Curation\Recommendation\Stream\Model\StreamEntryModel;
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

    /** @return list<StreamEntryModel> ascending by id, from the For you feeds the account is subscribed to */
    public function findRecentEntries(int $userId, \DateTimeImmutable $since, int $afterId, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'entry.id AS id',
                'entry.text.language AS entryLanguage',
                'feed.traits.language AS feedLanguage',
                'entry.headline.title AS title',
                'SUBSTRING(entry.text.summary, 1, 2000) AS summary',
            )
            ->from(Entry::class, 'entry')
            ->join('entry.feed', 'feed')
            ->join(Subscription::class, 'subscription', 'WITH', 'subscription.feed = feed')
            ->where('IDENTITY(subscription.user) = :user')
            ->andWhere('entry.dates.effectiveDate >= :since')
            ->andWhere('entry.id > :afterId')
            ->orderBy('entry.id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('user', $userId)
            ->setParameter('since', $since)
            ->setParameter('afterId', $afterId);
        InclusionScopeDql::restrict($qb, 'subscription', InclusionSurface::ForYou);

        /** @var list<array{id: int, entryLanguage: ?FeedLanguage, feedLanguage: ?FeedLanguage, title: string, summary: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): StreamEntryModel => new StreamEntryModel(
                $row['id'],
                $row['entryLanguage'] ?? $row['feedLanguage'],
                $row['title'],
                $row['summary'],
            ),
            $rows,
        );
    }
}
