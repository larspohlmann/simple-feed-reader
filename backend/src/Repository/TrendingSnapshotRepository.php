<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of the Trending tables: a rebuild replaces one window's stories and its snapshot row together.
 *
 * @extends ServiceEntityRepository<TrendingSnapshot>
 */
final class TrendingSnapshotRepository extends ServiceEntityRepository
{
    /** Rows per INSERT: 8 placeholders each, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 120;

    private const int SITE_LENGTH = 255;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingSnapshot::class);
    }

    public function lastBuilds(): TrendingLastBuildsModel
    {
        /** @var list<array{time_window: string, built_at: string, duration_ms: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()
            ->fetchAllAssociative('SELECT time_window, built_at, duration_ms FROM trending_snapshot');

        $builtAt = [];
        $durations = [];
        foreach ($rows as $row) {
            $builtAt[$row['time_window']] = new \DateTimeImmutable($row['built_at'], new \DateTimeZone('UTC'));
            $durations[$row['time_window']] = (int) $row['duration_ms'];
        }

        return new TrendingLastBuildsModel($builtAt, $durations);
    }

    /**
     * Deleting the stories placed in the window cascades to their members. The caller wraps this and
     * recordSnapshot() in one transaction, so readers keep the old snapshot until the commit (#2017).
     *
     * @param list<TrendingStoryModel> $stories
     */
    public function replaceStories(TrendingWindow $window, array $stories, \DateTimeImmutable $builtAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM trending_story WHERE id IN (SELECT member.story_id FROM trending_story_entry member WHERE '
            . TrendingPlacementSql::placedIn($window, 'member') . ')',
        );
        foreach ($stories as $story) {
            $this->insertStory($window, $story, $builtAt->format('Y-m-d H:i:s'));
        }
    }

    public function recordSnapshot(TrendingBuildReportModel $report): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM trending_snapshot WHERE time_window = ?', [$report->window->value]);
        $connection->insert('trending_snapshot', [
            'time_window' => $report->window->value,
            'built_at' => $report->builtAt->format('Y-m-d H:i:s'),
            'entry_count' => $report->entryCount,
            'story_count' => $report->storyCount,
            'duration_ms' => $report->durationMilliseconds,
            'peak_memory_bytes' => $report->peakMemoryBytes,
        ]);
    }

    private function insertStory(TrendingWindow $window, TrendingStoryModel $story, string $joinedAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->insert('trending_story', [
            'first_seen' => $story->firstSeen->format('Y-m-d H:i:s'),
            'last_seen' => $story->lastSeen->format('Y-m-d H:i:s'),
            'first_joined_at' => $joinedAt,
            'last_joined_at' => $joinedAt,
        ]);
        $storyId = (int) $connection->lastInsertId();
        $inDay = $window === TrendingWindow::Day ? 1 : 0;
        $inWeek = $window === TrendingWindow::Week ? 1 : 0;
        BulkInsert::into(
            'INSERT INTO trending_story_entry'
            . ' (story_id, entry_id, feed_id, article_site, shared_link_id, joined_at, in_day, in_week)',
            self::INSERT_ROWS,
        )->rows(
            $connection,
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
                $story->members,
            ),
        );
    }
}
