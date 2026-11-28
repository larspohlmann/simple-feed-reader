<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Enum\InclusionSurface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The reader's history in still-subscribed feeds, each entry only in its highest section (favorite, shared,
 * liked, kept, viewed). Liked and viewed take one line per post, from its lowest-id followed copy, and leave the
 * post out when any followed copy ranks in a higher section. Favorites and kept order by effectiveDate, since
 * EntryState has no favorited-at; shared orders by sharedAt, liked by likedAt or votedAt, and viewed by viewedAt.
 * With a surface, only entries of subscriptions that count in it are read; which section is highest stays blind to it.
 */
final readonly class ReadingHistoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?InclusionSurface $surface = null,
    ) {
    }

    #[\NoDiscard]
    public function withSurface(InclusionSurface $surface): self
    {
        return clone($this, ['surface' => $surface]);
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
            ->andWhere(EntryLikeDql::buildUpvoteCondition('liking'))
            ->setMaxResults($cap);
        $rows = [
            ...$this->readLikedRows($likes),
            ...$this->readLikedRows($upvotes),
            ...$this->readLikedRows($this->locallyLikedEntriesQueryBuilder($userId)->setMaxResults($cap)),
        ];

        $newestFirst = self::keepNewestLikePerPost($rows);
        usort(
            $newestFirst,
            static fn (array $left, array $right): int => [$right['likedAt'], $right['titled']->entry->requireId()]
                <=> [$left['likedAt'], $left['titled']->entry->requireId()],
        );

        return array_column(array_slice($newestFirst, 0, $cap), 'titled');
    }

    /** @return list<TitledEntry> */
    public function kept(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isKept = :true AND es.isFavorite = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . EntryLikeDql::existsOnJoinedState(EntryAliases::primary()))
            ->orderBy('e.dates.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function viewed(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isViewed = :true AND es.isFavorite = :false AND es.isKept = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . EntryLikeDql::existsOnJoinedState(EntryAliases::primary()))
            ->andWhere('NOT ' . self::buildFollowedCopyCondition(
                'sibling',
                'e.canonicalItem',
                self::buildCopyStateCondition(
                    'sibling',
                    static fn (string $state): string => "$state.isFavorite = :true OR $state.isKept = :true"
                        . " OR ($state.isViewed = :true AND sibling.id < e.id)",
                ) . ' OR ' . EntryShareDql::buildSharedCondition('sibling'),
            ))
            ->orderBy('es.viewedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    private function historyQueryBuilder(int $userId): QueryBuilder
    {
        return $this->subscribedEntriesQueryBuilder($userId, EntryState::class, 'es');
    }

    /** @param class-string $rootClass */
    private function subscribedEntriesQueryBuilder(int $userId, string $rootClass, string $rootAlias): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select($rootAlias, 'e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->from($rootClass, $rootAlias)
            ->join($rootAlias . '.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere(sprintf('IDENTITY(%s.user) = :user', $rootAlias))
            ->setParameter('user', $userId);

        return $this->restrictToSurface($qb);
    }

    /** @param class-string<SocialLike|DiscussionVote> $rootClass */
    private function likedEntriesQueryBuilder(int $userId, string $rootClass, string $instantField): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect(sprintf('liking.%s AS likedAt', $instantField))
            ->from($rootClass, 'liking')
            ->join(Entry::class, 'e', 'ON', 'e.canonicalItem = liking.canonicalItem')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(liking.user) = :user')
            ->andWhere('NOT ' . self::buildFollowedCopyCondition(
                'copy',
                'liking.canonicalItem',
                self::buildCopyStateCondition('copy', static fn (string $state): string => "$state.isFavorite = :true")
                    . ' OR ' . EntryShareDql::buildSharedCondition('copy'),
            ))
            ->andWhere('NOT ' . self::buildFollowedCopyCondition(
                'lowerCopy',
                'liking.canonicalItem',
                'lowerCopy.id < e.id',
            ))
            ->orderBy('liking.' . $instantField, 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN);

        return $this->restrictToSurface($qb);
    }

    private function restrictToSurface(QueryBuilder $qb): QueryBuilder
    {
        if ($this->surface !== null) {
            InclusionScopeDql::restrict($qb, 's', $this->surface);
        }

        return $qb;
    }

    private function locallyLikedEntriesQueryBuilder(int $userId): QueryBuilder
    {
        return $this->historyQueryBuilder($userId)
            ->addSelect('es.likedAt AS likedAt')
            ->andWhere(EntryLikeDql::buildLocalLikeCondition('es'))
            ->andWhere('es.isFavorite = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . self::buildFollowedCopyCondition(
                'copy',
                'e.canonicalItem',
                self::buildCopyStateCondition('copy', static fn (string $state): string => "$state.isFavorite = :true")
                    . ' OR ' . EntryShareDql::buildSharedCondition('copy'),
            ))
            ->orderBy('es.likedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);
    }

    /**
     * @param list<array{titled: TitledEntry, likedAt: \DateTimeImmutable}> $rows
     * @return list<array{titled: TitledEntry, likedAt: \DateTimeImmutable}>
     */
    private static function keepNewestLikePerPost(array $rows): array
    {
        $newest = [];
        foreach ($rows as $row) {
            $entry = $row['titled']->entry;
            $post = null === $entry->getCanonicalItemId()
                ? 'entry' . $entry->requireId()
                : 'item' . $entry->getCanonicalItemId();
            if (!isset($newest[$post]) || $newest[$post]['likedAt'] < $row['likedAt']) {
                $newest[$post] = $row;
            }
        }

        return array_values($newest);
    }

    /** A copy of the item, in a feed the reader follows, that meets $copyCondition. */
    private static function buildFollowedCopyCondition(
        string $copyAlias,
        string $itemExpression,
        string $copyCondition,
    ): string {
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s JOIN %3$s %2$sSubscription'
            . ' ON %2$sSubscription.feed = %2$s.feed AND %2$sSubscription.user = :user'
            . ' WHERE %2$s.canonicalItem = %4$s AND (%5$s))',
            Entry::class,
            $copyAlias,
            Subscription::class,
            $itemExpression,
            $copyCondition,
        );
    }

    /** @param \Closure(string): string $stateCondition takes the state's alias */
    private static function buildCopyStateCondition(string $copyAlias, \Closure $stateCondition): string
    {
        $state = $copyAlias . 'State';

        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.entry = %3$s AND %2$s.user = :user AND (%4$s))',
            EntryState::class,
            $state,
            $copyAlias,
            $stateCondition($state),
        );
    }

    /** @return list<array{titled: TitledEntry, likedAt: \DateTimeImmutable}> */
    private function readLikedRows(QueryBuilder $qb): array
    {
        /** @var list<array{0: Entry|EntryState, customTitle: ?string, likedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): array => [
                'titled' => TitledEntry::fromEntry(
                    $row[0] instanceof EntryState ? $row[0]->getEntry() : $row[0],
                    $row['customTitle'],
                ),
                'likedAt' => $row['likedAt'],
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
