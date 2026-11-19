<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Service\Curation\Trending\Model\FallbackTitleModel;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one writer of Trending's stories, their members and where each window places them (#2128, #2272).
 *
 * @phpstan-type FollowRow array{followed_at: string, last_opened_at: string}
 */
final readonly class TrendingStoryHistoryRepository
{
    /** Rows per statement: 6 placeholders a member row, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 120;

    private const int SITE_LENGTH = 255;

    private const int FALLBACK_TITLE_LENGTH = 1024;

    private const string MEMBER_COLUMNS = 'entry_id, feed_id, article_site, shared_link_id, joined_at';

    private const string INSERT_MEMBERS = 'INSERT INTO trending_story_entry (story_id, ' . self::MEMBER_COLUMNS . ')';

    /** MySQL refuses a subquery on the updated table (error 1093) unless a derived table stands between them. */
    private const string EARLIEST_SOURCE_JOIN = '(SELECT MIN(source.joined_at) FROM (SELECT entry_id, joined_at'
        . ' FROM trending_story_entry WHERE %s) source WHERE source.entry_id = trending_story_entry.entry_id)';

    private const string AMONG_SOURCE_ENTRIES = 'entry_id IN (SELECT entry_id FROM (SELECT entry_id'
        . ' FROM trending_story_entry WHERE %s) source)';

    /** Two readers' stories may hold the same entry: every read across stories by entry id stays in one reader's. */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<StoredStoryModel> the reader's stories with a member at or after $since, with those members */
    public function storedInWindow(int $userId, \DateTimeImmutable $since): array
    {
        /**
         * @var list<array{
         *   story_id: int|string, entry_id: int|string, fallback_entry_id: int|string|null, titled: int|string|bool
         * }> $rows
         */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT member.story_id, member.entry_id, story.fallback_entry_id,'
            . ' story.fallback_title IS NOT NULL AS titled FROM trending_story_entry member'
            . ' JOIN trending_story story ON story.id = member.story_id'
            . ' JOIN entry recent ON recent.id = member.entry_id WHERE story.user_id = ? AND recent.effective_date >= ?'
            . ' ORDER BY member.story_id, member.entry_id',
            [$userId, self::stored($since)],
        );
        $entryIdsByStory = [];
        $storyRows = [];
        foreach ($rows as $row) {
            $storyId = (int) $row['story_id'];
            $entryIdsByStory[$storyId][] = (int) $row['entry_id'];
            $storyRows[$storyId] ??= $row;
        }

        return array_map(
            static fn (int $storyId, array $entryIds): StoredStoryModel => new StoredStoryModel(
                $storyId,
                $entryIds,
                $storyRows[$storyId]['fallback_entry_id'] === null
                    ? null
                    : (int) $storyRows[$storyId]['fallback_entry_id'],
                (bool) $storyRows[$storyId]['titled'],
            ),
            array_keys($entryIdsByStory),
            $entryIdsByStory,
        );
    }

    /** Moves the merged story's members, placements, follows and hides onto the survivor, then deletes it. */
    public function merge(StoryMergeModel $merge): void
    {
        $this->absorb($merge->survivorId, \sprintf('story_id = %d', $merge->mergedId));
        $this->mergeFollows($merge);
        $this->mergeHides($merge);
        $this->widenToCover($merge);
        $this->connection()->executeStatement('DELETE FROM trending_story WHERE id = ?', [$merge->mergedId]);
    }

    /**
     * @param list<StoryAssignmentModel> $assignments
     *
     * @return array<int, int> the story each grouped entry now belongs to
     */
    public function saveAll(StoryBuildModel $build, array $assignments): array
    {
        $earliestJoins = $this->findEarliestJoins($build, $assignments);
        $storyIdByEntry = [];
        foreach ($assignments as $assignment) {
            $storyId = $this->save($assignment, $build, $earliestJoins);
            $storyIdByEntry += array_fill_keys($assignment->group->entryIds(), $storyId);
        }

        return $storyIdByEntry;
    }

    /**
     * Writes only the difference to what the window placed before.
     *
     * @param array<int, int> $storyIdByEntry
     *
     * @return int the entries whose placement changed
     */
    public function place(StoryBuildModel $build, array $storyIdByEntry): int
    {
        $placed = $this->placedIn($build);
        $leaving = array_diff_assoc($placed, $storyIdByEntry);
        $arriving = array_diff_assoc($storyIdByEntry, $placed);
        foreach (array_chunk(array_keys($leaving), self::INSERT_ROWS) as $chunk) {
            $this->connection()->executeStatement(
                'DELETE FROM trending_story_placement WHERE user_id = ? AND time_window = ? AND entry_id IN (?)',
                [$build->userId, $build->window->value, $chunk],
                [ParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::INTEGER],
            );
        }
        BulkInsert::into(
            'INSERT INTO trending_story_placement (user_id, time_window, entry_id, story_id)',
            self::INSERT_ROWS,
        )->rows(
            $this->connection(),
            array_map(
                static fn (int $entryId, int $storyId): array => [
                    $build->userId,
                    $build->window->value,
                    $entryId,
                    $storyId,
                ],
                array_keys($arriving),
                $arriving,
            ),
        );

        return \count($leaving + $arriving);
    }

    /** No future group can overlap a story without a member in the build's week; one a reader follows stays. */
    public function pruneUnfollowedOutside(StoryBuildModel $build): int
    {
        return (int) $this->connection()->executeStatement(
            'DELETE FROM trending_story WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM trending_story_follow follower'
            . ' WHERE follower.story_id = trending_story.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry member'
            . ' JOIN entry recent ON recent.id = member.entry_id'
            . ' WHERE member.story_id = trending_story.id AND recent.effective_date >= ?)',
            [$build->userId, self::stored($build->since)],
        );
    }

    /** @param array<int, string> $earliestJoins by entry id */
    private function save(StoryAssignmentModel $assignment, StoryBuildModel $build, array $earliestJoins): int
    {
        $joining = $assignment->joining();
        $joinedAts = self::buildJoinedAts($joining, $earliestJoins, $build->builtAt);
        $storyId = $assignment->storyId;
        if ($storyId === null) {
            $storyId = $this->insertStory($assignment, $build);
        } else {
            $this->updateStory($storyId, $assignment, $joinedAts);
        }
        $this->insertMembers($storyId, $joining, $joinedAts);
        foreach (array_chunk($assignment->claimedEntryIds, self::INSERT_ROWS) as $claimed) {
            $this->absorb($storyId, \sprintf(
                'story_id <> %d AND %s AND entry_id IN (%s)',
                $storyId,
                self::buildReaderCondition($build->userId),
                self::idList($claimed),
            ));
        }

        return $storyId;
    }

    /**
     * Moves the members $sourceRows selects onto the target, which keeps the earlier join of an entry it already
     * holds, along with their placements in every window.
     */
    private function absorb(int $targetId, string $sourceRows): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            self::INSERT_MEMBERS . ' SELECT ?, source.entry_id, MIN(source.feed_id), MIN(source.article_site),'
            . ' MIN(source.shared_link_id), MIN(source.joined_at) FROM trending_story_entry source WHERE ' . $sourceRows
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry held'
            . ' WHERE held.story_id = ? AND held.entry_id = source.entry_id) GROUP BY source.entry_id',
            [$targetId, $targetId],
        );
        $earliestSourceJoin = \sprintf(self::EARLIEST_SOURCE_JOIN, $sourceRows);
        $connection->executeStatement(
            'UPDATE trending_story_entry SET joined_at = ' . $earliestSourceJoin
            . ' WHERE story_id = ? AND ' . \sprintf(self::AMONG_SOURCE_ENTRIES, $sourceRows)
            . ' AND joined_at > ' . $earliestSourceJoin,
            [$targetId],
        );
        $connection->executeStatement(
            'UPDATE trending_story_placement SET story_id = ? WHERE ' . $sourceRows,
            [$targetId],
        );
        $connection->executeStatement('DELETE FROM trending_story_entry WHERE ' . $sourceRows);
    }

    /** A follower of the merged story finds its last join on the survivor, so the survivor does not turn quiet. */
    private function widenToCover(StoryMergeModel $merge): void
    {
        /** @var array{first_seen: string, last_seen: string, first_joined_at: string, last_joined_at: string} $merged */
        $merged = $this->connection()->fetchAssociative(
            'SELECT first_seen, last_seen, first_joined_at, last_joined_at FROM trending_story WHERE id = ?',
            [$merge->mergedId],
        );
        $this->connection()->executeStatement(
            'UPDATE trending_story SET '
            . self::earliest('first_seen', 'firstSeen') . ', ' . self::latest('last_seen', 'lastSeen') . ', '
            . self::earliest('first_joined_at', 'firstJoinedAt') . ', ' . self::latest('last_joined_at', 'lastJoinedAt')
            . ' WHERE id = :story',
            [
                'firstSeen' => $merged['first_seen'],
                'lastSeen' => $merged['last_seen'],
                'firstJoinedAt' => $merged['first_joined_at'],
                'lastJoinedAt' => $merged['last_joined_at'],
                'story' => $merge->survivorId,
            ],
        );
    }

    /** A reader following both keeps one follow with the earlier times, so nothing joined since goes unseen. */
    private function mergeFollows(StoryMergeModel $merge): void
    {
        $survivorFollows = $this->findFollows($merge->survivorId);
        foreach ($this->findFollows($merge->mergedId) as $userId => $follow) {
            $kept = $survivorFollows[$userId] ?? null;
            if ($kept === null) {
                $this->connection()->executeStatement(
                    'UPDATE trending_story_follow SET story_id = ? WHERE story_id = ? AND user_id = ?',
                    [$merge->survivorId, $merge->mergedId, $userId],
                );
                continue;
            }
            $this->connection()->executeStatement(
                'UPDATE trending_story_follow SET followed_at = ?, last_opened_at = ?'
                . ' WHERE story_id = ? AND user_id = ?',
                [
                    min($kept['followed_at'], $follow['followed_at']),
                    min($kept['last_opened_at'], $follow['last_opened_at']),
                    $merge->survivorId,
                    $userId,
                ],
            );
        }
    }

    private function mergeHides(StoryMergeModel $merge): void
    {
        $connection = $this->connection();
        /** @var list<array{user_id: int|string, hidden_at: string}> $hides */
        $hides = $connection->fetchAllAssociative(
            'SELECT user_id, hidden_at FROM trending_story_hide WHERE story_id = ?',
            [$merge->mergedId],
        );
        BulkInsert::into(
            \sprintf(
                'INSERT %s INTO trending_story_hide (user_id, story_id, hidden_at)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            self::INSERT_ROWS,
        )->rows(
            $connection,
            array_map(
                static fn (array $hide): array => [$hide['user_id'], $merge->survivorId, $hide['hidden_at']],
                $hides,
            ),
        );
    }

    /** @return array<int, int|string> the story of each entry the reader's window places */
    private function placedIn(StoryBuildModel $build): array
    {
        /** @var array<int, int|string> $placed */
        $placed = $this->connection()->fetchAllKeyValue(
            'SELECT entry_id, story_id FROM trending_story_placement WHERE user_id = ? AND time_window = ?',
            [$build->userId, $build->window->value],
        );

        return $placed;
    }

    /** @return array<int, FollowRow> by user id */
    private function findFollows(int $storyId): array
    {
        /** @var array<int, FollowRow> $follows */
        $follows = $this->connection()->fetchAllAssociativeIndexed(
            'SELECT user_id, followed_at, last_opened_at FROM trending_story_follow WHERE story_id = ?',
            [$storyId],
        );

        return $follows;
    }

    private function insertStory(StoryAssignmentModel $assignment, StoryBuildModel $build): int
    {
        $connection = $this->connection();
        $connection->insert('trending_story', [
            'user_id' => $build->userId,
            'first_seen' => self::stored($assignment->group->firstSeen),
            'last_seen' => self::stored($assignment->group->lastSeen),
            'first_joined_at' => self::stored($build->builtAt),
            'last_joined_at' => self::stored($build->builtAt),
            ...self::buildFallbackColumns($assignment->fallbackTitle),
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * The stored bounds only widen; the last join moves only to the latest join a member is given.
     *
     * @param array<int, string> $joinedAts
     */
    private function updateStory(int $storyId, StoryAssignmentModel $assignment, array $joinedAts): void
    {
        $this->connection()->executeStatement(
            'UPDATE trending_story SET '
            . self::earliest('first_seen', 'firstSeen') . ', ' . self::latest('last_seen', 'lastSeen') . ', '
            . self::latest('last_joined_at', 'lastJoinedAt') . ','
            . ' fallback_title = COALESCE(:fallback_title, fallback_title),'
            . ' fallback_entry_id'
            . ' = CASE WHEN :fallback_title IS NULL THEN fallback_entry_id ELSE :fallback_entry_id END'
            . ' WHERE id = :story',
            [
                'firstSeen' => self::stored($assignment->group->firstSeen),
                'lastSeen' => self::stored($assignment->group->lastSeen),
                'lastJoinedAt' => $joinedAts === [] ? null : max($joinedAts),
                'story' => $storyId,
                ...self::buildFallbackColumns($assignment->fallbackTitle),
            ],
        );
    }

    /** @return array{fallback_title: ?string, fallback_entry_id: ?int} */
    private static function buildFallbackColumns(?FallbackTitleModel $title): array
    {
        return [
            'fallback_title' => $title === null ? null : mb_substr($title->title, 0, self::FALLBACK_TITLE_LENGTH),
            'fallback_entry_id' => $title?->entryId,
        ];
    }

    /**
     * @param list<StoryMemberModel> $members
     * @param array<int, string>     $joinedAts by entry id
     */
    private function insertMembers(int $storyId, array $members, array $joinedAts): void
    {
        BulkInsert::into(self::INSERT_MEMBERS, self::INSERT_ROWS)->rows(
            $this->connection(),
            array_map(
                static fn (StoryMemberModel $member): array => [
                    $storyId,
                    $member->entryId,
                    $member->feedId,
                    $member->articleSite === null ? null : mb_substr($member->articleSite, 0, self::SITE_LENGTH),
                    $member->sharedLinkId,
                    $joinedAts[$member->entryId],
                ],
                $members,
            ),
        );
    }

    /**
     * A member another story of the reader holds keeps its earliest join, so regrouping never makes it new again.
     * Read once before the saves: a save's claims move rows but keep each entry's earliest join.
     *
     * @param list<StoryAssignmentModel> $assignments
     *
     * @return array<int, string> by entry id
     */
    private function findEarliestJoins(StoryBuildModel $build, array $assignments): array
    {
        $entryIds = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment->joining() as $member) {
                $entryIds[] = $member->entryId;
            }
        }
        $earliestJoins = [];
        foreach (array_chunk($entryIds, self::INSERT_ROWS) as $chunk) {
            /** @var array<int, string> $chunkJoins */
            $chunkJoins = $this->connection()->fetchAllKeyValue(
                'SELECT entry_id, MIN(joined_at) FROM trending_story_entry WHERE entry_id IN (?) AND '
                . self::buildReaderCondition($build->userId) . ' GROUP BY entry_id',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
            $earliestJoins += $chunkJoins;
        }

        return $earliestJoins;
    }

    /**
     * @param list<StoryMemberModel> $members
     * @param array<int, string>     $earliestJoins by entry id
     *
     * @return array<int, string> by entry id
     */
    private static function buildJoinedAts(array $members, array $earliestJoins, \DateTimeImmutable $builtAt): array
    {
        $joinedAts = [];
        foreach ($members as $member) {
            $joinedAts[$member->entryId] = $earliestJoins[$member->entryId] ?? self::stored($builtAt);
        }

        return $joinedAts;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /** SQLite has no LEAST or GREATEST, MySQL no two-argument MIN or MAX. */
    private static function earliest(string $column, string $parameter): string
    {
        return \sprintf('%1$s = CASE WHEN %1$s < :%2$s THEN %1$s ELSE :%2$s END', $column, $parameter);
    }

    private static function latest(string $column, string $parameter): string
    {
        return \sprintf('%1$s = CASE WHEN :%2$s > %1$s THEN :%2$s ELSE %1$s END', $column, $parameter);
    }

    /** @param list<int> $ids typed integers, safe to write into the statement */
    private static function idList(array $ids): string
    {
        return implode(', ', $ids);
    }

    /** Two readers' stories may hold the same entry: every read across stories by entry id stays in one reader's. */
    private static function buildReaderCondition(int $userId): string
    {
        return \sprintf('story_id IN (SELECT id FROM trending_story WHERE user_id = %d)', $userId);
    }

    private static function stored(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
