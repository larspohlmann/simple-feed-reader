<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingSnapshot;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of Trending's snapshot rows: each build replaces its user's window.
 *
 * @extends ServiceEntityRepository<TrendingSnapshot>
 */
final class TrendingSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrendingSnapshot::class);
    }

    public function lastBuilds(int $userId): TrendingLastBuildsModel
    {
        /** @var list<array{time_window: string, built_at: string, duration_ms: int|string, active_feed_count: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT time_window, built_at, duration_ms, active_feed_count FROM trending_snapshot WHERE user_id = ?',
            [$userId],
        );

        $builtAt = [];
        $durations = [];
        $activeFeedCounts = [];
        foreach ($rows as $row) {
            $builtAt[$row['time_window']] = NaiveUtc::parse($row['built_at']);
            $durations[$row['time_window']] = (int) $row['duration_ms'];
            $activeFeedCounts[$row['time_window']] = (int) $row['active_feed_count'];
        }

        return new TrendingLastBuildsModel($builtAt, $durations, $activeFeedCounts);
    }

    /** The caller writes the stories and this row in one transaction, so the reader keeps the old ones until the commit. */
    public function recordSnapshot(TrendingBuildReportModel $report): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM trending_snapshot WHERE user_id = ? AND time_window = ?',
            [$report->userId, $report->window->value],
        );
        $connection->insert('trending_snapshot', [
            'user_id' => $report->userId,
            'time_window' => $report->window->value,
            'built_at' => $report->builtAt->format('Y-m-d H:i:s'),
            'entry_count' => $report->entryCount,
            'active_feed_count' => $report->activeFeedCount,
            'story_count' => $report->storyCount,
            'duration_ms' => $report->durationMilliseconds,
            'peak_memory_bytes' => $report->peakMemoryBytes,
        ]);
    }
}
