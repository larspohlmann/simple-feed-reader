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
        return $this->titledEntries($this->favoritesQueryBuilder($userId, $cap));
    }

    /** @return list<TitledEntry> */
    public function shared(int $userId, int $cap): array
    {
        return $this->titledEntries($this->sharedQueryBuilder($userId, $cap));
    }

    /** @return list<TitledEntry> */
    public function liked(int $userId, int $cap): array
    {
        $rows = [];
        foreach ($this->likedQueries($userId, $cap) as [$qb, $likedAt]) {
            $rows = [...$rows, ...$this->readLikedRows($qb->addSelect($likedAt . ' AS likedAt'))];
        }

        return self::pickNewestLikes($rows, $cap);
    }

    /** @return list<TitledEntry> */
    public function kept(int $userId, int $cap): array
    {
        return $this->titledEntries($this->keptQueryBuilder($userId, $cap));
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

    /** @return list<int> the favorites' entry ids, only of subscriptions that count in $surface */
    public function findFavoriteIds(int $userId, int $cap, InclusionSurface $surface): array
    {
        return $this->readEntryIds($this->favoritesQueryBuilder($userId, $cap), $surface);
    }

    /** @return list<int> the shared entries' ids, only of subscriptions that count in $surface */
    public function findSharedIds(int $userId, int $cap, InclusionSurface $surface): array
    {
        return $this->readEntryIds($this->sharedQueryBuilder($userId, $cap), $surface);
    }

    /** @return list<int> the liked entries' ids, only of subscriptions that count in $surface */
    public function findLikedIds(int $userId, int $cap, InclusionSurface $surface): array
    {
        $rows = [];
        foreach ($this->likedQueries($userId, $cap) as [$qb, $likedAt]) {
            InclusionScopeDql::restrict($qb, 's', $surface);
            $qb->select('e.id AS entryId', 'IDENTITY(e.canonicalItem) AS canonicalItemId', $likedAt . ' AS likedAt');
            $rows = [...$rows, ...self::readLikedIdRows($qb)];
        }

        return self::pickNewestLikes($rows, $cap);
    }

    /** @return list<int> the kept entries' ids, only of subscriptions that count in $surface */
    public function findKeptIds(int $userId, int $cap, InclusionSurface $surface): array
    {
        return $this->readEntryIds($this->keptQueryBuilder($userId, $cap), $surface);
    }

    private function favoritesQueryBuilder(int $userId, int $cap): QueryBuilder
    {
        return $this->historyQueryBuilder($userId)
            ->andWhere('es.isFavorite = :true')
            ->orderBy('e.dates.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN);
    }

    private function sharedQueryBuilder(int $userId, int $cap): QueryBuilder
    {
        return $this->subscribedEntriesQueryBuilder($userId, EntryShare::class, 'share')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('es.isFavorite IS NULL OR es.isFavorite = :false')
            ->orderBy('share.sharedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('false', false, Types::BOOLEAN);
    }

    /** @return list<array{QueryBuilder, string}> each like source with the expression of its liked-at instant */
    private function likedQueries(int $userId, int $cap): array
    {
        return [
            [
                $this->likedEntriesQueryBuilder($userId, SocialLike::class, 'likedAt')->setMaxResults($cap),
                'liking.likedAt',
            ],
            [
                $this->likedEntriesQueryBuilder($userId, DiscussionVote::class, 'votedAt')
                    ->andWhere(EntryLikeDql::buildUpvoteCondition('liking'))
                    ->setMaxResults($cap),
                'liking.votedAt',
            ],
            [$this->locallyLikedEntriesQueryBuilder($userId)->setMaxResults($cap), 'es.likedAt'],
        ];
    }

    private function keptQueryBuilder(int $userId, int $cap): QueryBuilder
    {
        return $this->historyQueryBuilder($userId)
            ->andWhere('es.isKept = :true AND es.isFavorite = :false')
            ->andWhere('NOT ' . EntryShareDql::exists(EntryAliases::primary()))
            ->andWhere('NOT ' . EntryLikeDql::existsOnJoinedState(EntryAliases::primary()))
            ->orderBy('e.dates.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);
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
     * @template T
     *
     * @param list<array{post: string, entryId: int, likedAt: \DateTimeImmutable, line: T}> $rows
     *
     * @return list<T> newest first, one line per post: its newest like
     */
    private static function pickNewestLikes(array $rows, int $cap): array
    {
        $newest = [];
        foreach ($rows as $row) {
            if (!isset($newest[$row['post']]) || $newest[$row['post']]['likedAt'] < $row['likedAt']) {
                $newest[$row['post']] = $row;
            }
        }
        $newestFirst = array_values($newest);
        usort(
            $newestFirst,
            static fn (array $left, array $right): int => [$right['likedAt'], $right['entryId']]
                <=> [$left['likedAt'], $left['entryId']],
        );

        return array_column(array_slice($newestFirst, 0, $cap), 'line');
    }

    private static function buildPostKey(int $entryId, ?int $canonicalItemId): string
    {
        return null === $canonicalItemId ? 'entry' . $entryId : 'item' . $canonicalItemId;
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

    /** @return list<array{post: string, entryId: int, likedAt: \DateTimeImmutable, line: TitledEntry}> */
    private function readLikedRows(QueryBuilder $qb): array
    {
        /** @var list<array{0: Entry|EntryState, customTitle: ?string, likedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static function (array $row): array {
                $entry = $row[0] instanceof EntryState ? $row[0]->getEntry() : $row[0];

                return [
                    'post' => self::buildPostKey($entry->requireId(), $entry->getCanonicalItemId()),
                    'entryId' => $entry->requireId(),
                    'likedAt' => $row['likedAt'],
                    'line' => TitledEntry::fromEntry($entry, $row['customTitle']),
                ];
            },
            $rows,
        );
    }

    /** @return list<array{post: string, entryId: int, likedAt: \DateTimeImmutable, line: int}> */
    private static function readLikedIdRows(QueryBuilder $qb): array
    {
        /** @var list<array{entryId: int|string, canonicalItemId: int|string|null, likedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static function (array $row): array {
                $entryId = (int) $row['entryId'];
                $canonicalItemId = null === $row['canonicalItemId'] ? null : (int) $row['canonicalItemId'];

                return [
                    'post' => self::buildPostKey($entryId, $canonicalItemId),
                    'entryId' => $entryId,
                    'likedAt' => $row['likedAt'],
                    'line' => $entryId,
                ];
            },
            $rows,
        );
    }

    /** @return list<int> */
    private function readEntryIds(QueryBuilder $qb, InclusionSurface $surface): array
    {
        InclusionScopeDql::restrict($qb, 's', $surface);
        /** @var list<array{entryId: int|string}> $rows */
        $rows = $qb->select('e.id AS entryId')->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['entryId'], $rows);
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
