<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\TrendingStoryEntry;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
use App\Service\Curation\Trending\Model\TrendingStoryTallyModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
final class TrendingStoryEntryListRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly DuplicateCollapseDql $collapse,
        private readonly EntryProjection $projection,
        private readonly EntryScopePredicates $scopes,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /** @return StoryHeadingModel|null null for a story with no stored title and no member in a feed its reader subscribes to */
    public function findHeading(TrendingStoryListQuery $query): ?StoryHeadingModel
    {
        /** @var array{title: string|null, synopsis: string|null}|false $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT ' . TrendingStoryTitleSql::TITLE . ' AS title, story.llm_synopsis AS synopsis'
            . ' FROM trending_story story WHERE story.id = ? AND story.user_id = ?',
            [$query->storyId, $query->userId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        if ($row === false || $row['title'] === null) {
            return null;
        }

        return new StoryHeadingModel($row['title'], $row['synopsis']);
    }

    /** @return list<EntryListRow> */
    public function listMembers(TrendingStoryListQuery $query): array
    {
        $ordering = $query->page->ordering();
        $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($query->userId), $ordering)
            ->setMaxResults($query->page->limit)
            ->setParameter('story', $query->storyId);
        $this->applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $this->applyMembership(...), $query->userId);
        if ($query->page->onlyUnread) {
            $this->scopes->unread($qb, EntryAliases::primary());
        }
        $this->projection->applyCursor($qb, $query->page->cursor, $ordering);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    public function countMembersAndUnread(TrendingStoryListQuery $query): TrendingStoryTallyModel
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) AS memberCount')
            ->addSelect(\sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS unreadCount', UnreadDql::predicate()))
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->setParameter('notHidden', false, Types::BOOLEAN)
            ->setParameter('story', $query->storyId);
        $this->applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $this->applyMembership(...), $query->userId);

        /** @var array{memberCount: int|string, unreadCount: int|string|null} $row */
        $row = $qb->getQuery()->getSingleResult();

        return new TrendingStoryTallyModel((int) $row['memberCount'], (int) $row['unreadCount']);
    }

    /** @return list<int> the story's members the reader has not read, no newer than $until */
    public function unreadMemberIdsUpTo(TrendingStoryListQuery $query, \DateTimeImmutable $until): array
    {
        $qb = $this->projection->unreadEntriesQueryBuilder($query->userId)
            ->select('e.id')
            ->andWhere('e.dates.effectiveDate <= :until')
            ->setParameter('until', $until)
            ->setParameter('story', $query->storyId);
        $this->applyMembership($qb, EntryAliases::primary());

        return $this->projection->scalarIds($qb);
    }

    /**
     * When each given entry joined the story: the latest join among it and the collapsed copies the reader sees.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function joinedAtByEntry(TrendingStoryListQuery $query, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('e')
            ->select('e.id AS id', 'MAX(joined.joinedAt) AS joinedAt')
            ->join(TrendingStoryEntry::class, 'joined', 'ON', 'joined.story = :story')
            ->join('joined.entry', 'joinedEntry')
            ->join(
                Subscription::class,
                'joinedReader',
                'ON',
                'joinedReader.feed = joinedEntry.feed AND joinedReader.user = :user',
            )
            ->andWhere('joinedEntry = e OR joinedEntry.location.urlHash = e.location.urlHash')
            ->andWhere('e.id IN (:entryIds)')
            ->groupBy('e.id')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('user', $query->userId)
            ->setParameter('story', $query->storyId);

        /** @var list<array{id: int|string, joinedAt: string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        $joinedAt = [];
        foreach ($rows as $row) {
            $joinedAt[(int) $row['id']] = NaiveUtc::parse($row['joinedAt']);
        }

        return $joinedAt;
    }

    /** On the primary alias and inside the collapse subquery alike, or a collapsed copy punches a hole in the page. */
    private function applyMembership(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $member = 'storyMember' . ucfirst($aliases->entry);
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.entry = %3$s AND %2$s.story = :story)',
            TrendingStoryEntry::class,
            $member,
            $aliases->entry,
        ));
    }
}
