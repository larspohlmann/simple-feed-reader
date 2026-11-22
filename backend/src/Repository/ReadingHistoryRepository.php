<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Enum\VoteScore;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The reader's history in still-subscribed feeds, each entry only in its highest section (favorite, shared,
 * liked, kept, viewed). Favorites and kept order by effectiveDate, since EntryState has no favorited-at; shared
 * orders by sharedAt, liked by likedAt or votedAt, and viewed by viewedAt.
 */
final readonly class ReadingHistoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<TitledEntry> */
    public function favorites(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isFavorite = :true')
            ->orderBy('e.dates.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function shared(int $userId, int $cap): array
    {
        $qb = $this->subscribedEntriesQueryBuilder($userId, EntryShare::class, 'share')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('es.isFavorite IS NULL OR es.isFavorite = :false')
            ->orderBy('share.sharedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function liked(int $userId, int $cap): array
    {
        $likes = $this->likedEntriesQueryBuilder($userId, SocialLike::class, 'likedAt')->setMaxResults($cap);
        $upvotes = $this->likedEntriesQueryBuilder($userId, DiscussionVote::class, 'votedAt')
            ->andWhere('liking.score = :upvote')
            ->setParameter('upvote', VoteScore::Up)
            ->setMaxResults($cap);
        $rows = [...$this->readLikedRows($likes), ...$this->readLikedRows($upvotes)];
        usort(
            $rows,
            static fn (array $left, array $right): int => [$right['likedAt'], $right['entryId']]
                <=> [$left['likedAt'], $left['entryId']],
        );

        return array_column(array_slice($rows, 0, $cap), 'titled');
    }

    /** @return list<TitledEntry> */
    public function kept(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isKept = :true AND es.isFavorite = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . EntryLikeDql::exists(EntryAliases::primary()))
            ->orderBy('e.dates.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('upvote', VoteScore::Up);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function viewed(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isViewed = :true AND es.isFavorite = :false AND es.isKept = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . EntryLikeDql::exists(EntryAliases::primary()))
            ->orderBy('es.viewedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('upvote', VoteScore::Up);

        return $this->titledEntries($qb);
    }

    private function historyQueryBuilder(int $userId): QueryBuilder
    {
        return $this->subscribedEntriesQueryBuilder($userId, EntryState::class, 'es');
    }

    /** @param class-string $rootClass */
    private function subscribedEntriesQueryBuilder(int $userId, string $rootClass, string $rootAlias): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select($rootAlias, 'e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->from($rootClass, $rootAlias)
            ->join($rootAlias . '.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere(sprintf('IDENTITY(%s.user) = :user', $rootAlias))
            ->setParameter('user', $userId);
    }

    /** @param class-string<SocialLike|DiscussionVote> $rootClass */
    private function likedEntriesQueryBuilder(int $userId, string $rootClass, string $instantField): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect(sprintf('liking.%s AS likedAt', $instantField))
            ->from($rootClass, 'liking')
            ->join(Entry::class, 'e', 'ON', 'e.canonicalItem = liking.canonicalItem')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('IDENTITY(liking.user) = :user')
            ->andWhere('es.isFavorite IS NULL OR es.isFavorite = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s lowerCopy JOIN %s lowerSubscription'
                . ' ON lowerSubscription.feed = lowerCopy.feed AND lowerSubscription.user = :user'
                . ' WHERE lowerCopy.canonicalItem = liking.canonicalItem AND lowerCopy.id < e.id)',
                Entry::class,
                Subscription::class,
            ))
            ->orderBy('liking.' . $instantField, 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('user', $userId)
            ->setParameter('false', false, Types::BOOLEAN);
    }

    /** @return list<array{titled: TitledEntry, likedAt: \DateTimeImmutable, entryId: int}> */
    private function readLikedRows(QueryBuilder $qb): array
    {
        /** @var list<array{0: Entry, customTitle: ?string, likedAt: \DateTimeImmutable}> $rows feed folds into row 0's graph */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): array => [
                'titled' => TitledEntry::fromEntry($row[0], $row['customTitle']),
                'likedAt' => $row['likedAt'],
                'entryId' => $row[0]->requireId(),
            ],
            $rows,
        );
    }

    /** @return list<TitledEntry> */
    private function titledEntries(QueryBuilder $qb): array
    {
        /** @var list<array{0: EntryState|EntryShare, customTitle: ?string}> $rows entry and feed fold into row 0's graph */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): TitledEntry => TitledEntry::fromEntry($row[0]->getEntry(), $row['customTitle']),
            $rows,
        );
    }
}
