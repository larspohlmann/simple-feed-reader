<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\StoryMergeModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one writer of Trending's stories and their members: a build updates them in place (#2128).
 *
 * @phpstan-type MemberRow array{
 *     entry_id: int|string,
 *     feed_id: int|string,
 *     article_site: string|null,
 *     shared_link_id: int|string|null,
 *     joined_at: string,
 *     in_day: int|string,
 *     in_week: int|string,
 * }
 * @phpstan-type FollowRow array{followed_at: string, last_opened_at: string}
 */
final readonly class TrendingStoryHistoryRepository
{
    /** Rows per statement: 8 placeholders a member row, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 120;

    private const int SITE_LENGTH = 255;

    private const string MEMBER_COLUMNS = 'entry_id, feed_id, article_site, shared_link_id, joined_at, in_day, in_week';

    private const string INSERT_MEMBERS = 'INSERT INTO trending_story_entry (story_id, ' . self::MEMBER_COLUMNS . ')';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<StoredStoryModel> every story with a member dated at or after $since, with those members */
    public function storedInWindow(\DateTimeImmutable $since): array
    {
        /** @var list<array{story_id: int|string, entry_id: int|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT member.story_id, member.entry_id FROM trending_story_entry member'
            . ' JOIN entry recent ON recent.id = member.entry_id WHERE recent.effective_date >= ?'
            . ' ORDER BY member.story_id, member.entry_id',
            [self::stored($since)],
        );
        $entryIdsByStory = [];
        foreach ($rows as $row) {
            $entryIdsByStory[(int) $row['story_id']][] = (int) $row['entry_id'];
        }

        return array_map(
            static fn (int $storyId, array $entryIds): StoredStoryModel => new StoredStoryModel($storyId, $entryIds),
            array_keys($entryIdsByStory),
            $entryIdsByStory,
        );
    }

    /** Moves the merged story's members, follows and hides onto the survivor, then deletes the merged story. */
    public function merge(StoryMergeModel $merge): void
    {
        $this->absorbMembers($merge->survivorId, $this->membersOf($merge->mergedId));
        $this->mergeFollows($merge);
        $this->mergeHides($merge);
        $this->widenToCover($merge);
        $this->connection()->executeStatement('DELETE FROM trending_story WHERE id = ?', [$merge->mergedId]);
    }

    public function clearPlacement(TrendingWindow $window): void
    {
        $this->connection()->executeStatement(
            \sprintf('UPDATE trending_story_entry SET %1$s = 0 WHERE %1$s = 1', TrendingPlacementSql::flagOf($window)),
        );
    }

    public function save(StoryAssignmentModel $assignment, StoryBuildModel $build): void
    {
        $storyId = $assignment->storyId;
        if ($storyId === null) {
            $storyId = $this->insertStory($assignment->group, $build->builtAt);
        } else {
            $this->updateStory($storyId, $assignment, $build->builtAt);
        }
        $this->placeStaying($storyId, $assignment->staying(), $build->window);
        $this->insertMembers($storyId, $assignment->joining(), $build);
        if ($assignment->claimedEntryIds !== []) {
            $this->claimFromOtherStories($storyId, $assignment->claimedEntryIds);
        }
    }

    /** No future group can overlap a story without a member in the week; one a reader follows stays. */
    public function pruneUnfollowedOutside(\DateTimeImmutable $weekStart): int
    {
        return (int) $this->connection()->executeStatement(
            'DELETE FROM trending_story WHERE NOT EXISTS (SELECT 1 FROM trending_story_follow follower'
            . ' WHERE follower.story_id = trending_story.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry member'
            . ' JOIN entry recent ON recent.id = member.entry_id'
            . ' WHERE member.story_id = trending_story.id AND recent.effective_date >= ?)',
            [self::stored($weekStart)],
        );
    }

    /**
     * The week places an entry in one story only, or the next day build ties it back to the story it left.
     *
     * @param non-empty-list<int> $entryIds
     */
    private function claimFromOtherStories(int $storyId, array $entryIds): void
    {
        foreach (array_chunk($entryIds, self::INSERT_ROWS) as $chunk) {
            $this->claimChunk($storyId, $chunk);
        }
    }

    /** @param list<int> $entryIds */
    private function claimChunk(int $storyId, array $entryIds): void
    {
        $elsewhere = $this->membersOfOtherStories($storyId, $entryIds);
        if ($elsewhere === []) {
            return;
        }
        $this->absorbMembers($storyId, $elsewhere);
        $this->connection()->executeStatement(
            'DELETE FROM trending_story_entry WHERE story_id <> ? AND entry_id IN (?)',
            [$storyId, $entryIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
    }

    /**
     * An entry the target already holds keeps the earlier join and both placements; the rest move as they are.
     *
     * @param list<MemberRow> $members
     */
    private function absorbMembers(int $targetId, array $members): void
    {
        $held = array_column($this->membersOf($targetId), null, 'entry_id');
        $moving = [];
        foreach ($members as $member) {
            $entryId = (int) $member['entry_id'];
            $kept = $held[$entryId] ?? null;
            if ($kept === null) {
                $moving[] = [$targetId, ...array_values($member)];
                continue;
            }
            $held[$entryId] = self::combined($kept, $member);
            $this->combineMember($targetId, $entryId, $held[$entryId]);
        }
        BulkInsert::into(self::INSERT_MEMBERS, self::INSERT_ROWS)->rows($this->connection(), $moving);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<MemberRow>
     */
    private function membersOfOtherStories(int $storyId, array $entryIds): array
    {
        /** @var list<MemberRow> $members */
        $members = $this->connection()->fetchAllAssociative(
            'SELECT ' . self::MEMBER_COLUMNS . ' FROM trending_story_entry WHERE story_id <> ? AND entry_id IN (?)',
            [$storyId, $entryIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        return $members;
    }

    /** @param MemberRow $member */
    private function combineMember(int $storyId, int $entryId, array $member): void
    {
        $this->connection()->executeStatement(
            'UPDATE trending_story_entry SET joined_at = ?, in_day = ?, in_week = ?'
            . ' WHERE story_id = ? AND entry_id = ?',
            [$member['joined_at'], $member['in_day'], $member['in_week'], $storyId, $entryId],
        );
    }

    /**
     * @param MemberRow $kept
     * @param MemberRow $other
     *
     * @return MemberRow the kept row with the earlier join and the placement of both
     */
    private static function combined(array $kept, array $other): array
    {
        return [
            'joined_at' => min($kept['joined_at'], $other['joined_at']),
            'in_day' => max((int) $kept['in_day'], (int) $other['in_day']),
            'in_week' => max((int) $kept['in_week'], (int) $other['in_week']),
        ] + $kept;
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
        $survivorFollows = $this->followsOf($merge->survivorId);
        foreach ($this->followsOf($merge->mergedId) as $userId => $follow) {
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

    /** @return list<MemberRow> */
    private function membersOf(int $storyId): array
    {
        /** @var list<MemberRow> $members */
        $members = $this->connection()->fetchAllAssociative(
            'SELECT ' . self::MEMBER_COLUMNS . ' FROM trending_story_entry WHERE story_id = ?',
            [$storyId],
        );

        return $members;
    }

    /** @return array<int, FollowRow> by user id */
    private function followsOf(int $storyId): array
    {
        /** @var array<int, FollowRow> $follows */
        $follows = $this->connection()->fetchAllAssociativeIndexed(
            'SELECT user_id, followed_at, last_opened_at FROM trending_story_follow WHERE story_id = ?',
            [$storyId],
        );

        return $follows;
    }

    private function insertStory(TrendingStoryModel $group, \DateTimeImmutable $builtAt): int
    {
        $connection = $this->connection();
        $connection->insert('trending_story', [
            'first_seen' => self::stored($group->firstSeen),
            'last_seen' => self::stored($group->lastSeen),
            'first_joined_at' => self::stored($builtAt),
            'last_joined_at' => self::stored($builtAt),
        ]);

        return (int) $connection->lastInsertId();
    }

    /** The stored bounds only widen; the last join moves only when a member joins. */
    private function updateStory(int $storyId, StoryAssignmentModel $assignment, \DateTimeImmutable $builtAt): void
    {
        $this->connection()->executeStatement(
            'UPDATE trending_story SET '
            . self::earliest('first_seen', 'firstSeen') . ', ' . self::latest('last_seen', 'lastSeen') . ','
            . ' last_joined_at = COALESCE(:lastJoinedAt, last_joined_at)'
            . ' WHERE id = :story',
            [
                'firstSeen' => self::stored($assignment->group->firstSeen),
                'lastSeen' => self::stored($assignment->group->lastSeen),
                'lastJoinedAt' => $assignment->joining() === [] ? null : self::stored($builtAt),
                'story' => $storyId,
            ],
        );
    }

    /** @param list<int> $entryIds */
    private function placeStaying(int $storyId, array $entryIds, TrendingWindow $window): void
    {
        foreach (array_chunk($entryIds, self::INSERT_ROWS) as $chunk) {
            $this->connection()->executeStatement(
                \sprintf(
                    'UPDATE trending_story_entry SET %s = 1 WHERE story_id = ? AND entry_id IN (?)',
                    TrendingPlacementSql::flagOf($window),
                ),
                [$storyId, $chunk],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        }
    }

    /** @param list<StoryMemberModel> $members */
    private function insertMembers(int $storyId, array $members, StoryBuildModel $build): void
    {
        $joinedAt = self::stored($build->builtAt);
        $placement = TrendingPlacementSql::placementIn($build->window);
        BulkInsert::into(self::INSERT_MEMBERS, self::INSERT_ROWS)->rows(
            $this->connection(),
            array_map(
                static fn (StoryMemberModel $member): array => [
                    $storyId,
                    $member->entryId,
                    $member->feedId,
                    $member->articleSite === null ? null : mb_substr($member->articleSite, 0, self::SITE_LENGTH),
                    $member->sharedLinkId,
                    $joinedAt,
                    ...$placement,
                ],
                $members,
            ),
        );
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
        return \sprintf('%1$s = CASE WHEN %1$s > :%2$s THEN %1$s ELSE :%2$s END', $column, $parameter);
    }

    private static function stored(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
