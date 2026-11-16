<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrendingSnapshot;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of Trending's snapshot rows: each build replaces its window's.
 *
 * @extends ServiceEntityRepository<TrendingSnapshot>
 */
final class TrendingSnapshotRepository extends ServiceEntityRepository
{
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

    /** The caller writes the stories and this row in one transaction, so readers keep the old ones until the commit. */
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
}
