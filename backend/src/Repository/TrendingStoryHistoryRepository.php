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
 *     feed_id: int|string,
 *     article_site: string|null,
 *     shared_link_id: int|string|null,
 *     joined_at: string,
 *     in_day: bool|int|string,
 *     in_week: bool|int|string,
 * }
 * @phpstan-type FollowRow array{followed_at: string, last_opened_at: string}
 */
final readonly class TrendingStoryHistoryRepository
{
    /** Rows per statement: 8 placeholders a member row, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 120;

    private const int SITE_LENGTH = 255;

    private const string INSERT_MEMBERS = 'INSERT INTO trending_story_entry'
        . ' (story_id, entry_id, feed_id, article_site, shared_link_id, joined_at, in_day, in_week)';

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
            array_values($entryIdsByStory),
        );
    }

    /** Moves the merged story's members, follows and hides onto the survivor, then deletes the merged story. */
    public function merge(StoryMergeModel $merge): void
    {
        $this->mergeMembers($merge);
        $this->mergeFollows($merge);
        $this->mergeHides($merge);
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

    /** A shared entry keeps the earlier join and both stories' placement; the rest move as they are. */
    private function mergeMembers(StoryMergeModel $merge): void
    {
        $survivorMembers = $this->membersOf($merge->survivorId);
        $moving = [];
        foreach ($this->membersOf($merge->mergedId) as $entryId => $member) {
            $kept = $survivorMembers[$entryId] ?? null;
            if ($kept === null) {
                $moving[] = [
                    $merge->survivorId,
                    $entryId,
                    (int) $member['feed_id'],
                    $member['article_site'],
                    $member['shared_link_id'] === null ? null : (int) $member['shared_link_id'],
                    $member['joined_at'],
                    (int) $member['in_day'],
                    (int) $member['in_week'],
                ];
                continue;
            }
            $this->combineMember($merge->survivorId, $entryId, $kept, $member);
        }
        BulkInsert::into(self::INSERT_MEMBERS, self::INSERT_ROWS)->rows($this->connection(), $moving);
    }

    /**
     * @param MemberRow $kept
     * @param MemberRow $merged
     */
    private function combineMember(int $survivorId, int $entryId, array $kept, array $merged): void
    {
        $this->connection()->executeStatement(
            'UPDATE trending_story_entry SET joined_at = ?, in_day = ?, in_week = ?'
            . ' WHERE story_id = ? AND entry_id = ?',
            [
                min($kept['joined_at'], $merged['joined_at']),
                max((int) $kept['in_day'], (int) $merged['in_day']),
                max((int) $kept['in_week'], (int) $merged['in_week']),
                $survivorId,
                $entryId,
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
                static fn (array $hide): array => [(int) $hide['user_id'], $merge->survivorId, $hide['hidden_at']],
                $hides,
            ),
        );
    }

    /** @return array<int, MemberRow> by entry id */
    private function membersOf(int $storyId): array
    {
        /** @var array<int, MemberRow> $members */
        $members = $this->connection()->fetchAllAssociativeIndexed(
            'SELECT entry_id, feed_id, article_site, shared_link_id, joined_at, in_day, in_week'
            . ' FROM trending_story_entry WHERE story_id = ?',
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
            'UPDATE trending_story SET'
            . ' first_seen = CASE WHEN first_seen < :firstSeen THEN first_seen ELSE :firstSeen END,'
            . ' last_seen = CASE WHEN last_seen > :lastSeen THEN last_seen ELSE :lastSeen END,'
            . ' last_joined_at = CASE WHEN :joins = 1 THEN :builtAt ELSE last_joined_at END'
            . ' WHERE id = :story',
            [
                'firstSeen' => self::stored($assignment->group->firstSeen),
                'lastSeen' => self::stored($assignment->group->lastSeen),
                'joins' => $assignment->joining() === [] ? 0 : 1,
                'builtAt' => self::stored($builtAt),
                'story' => $storyId,
            ],
            ['joins' => ParameterType::INTEGER, 'story' => ParameterType::INTEGER],
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
        $inDay = $build->window === TrendingWindow::Day ? 1 : 0;
        $inWeek = $build->window === TrendingWindow::Week ? 1 : 0;
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
                    $inDay,
                    $inWeek,
                ],
                $members,
            ),
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private static function stored(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
