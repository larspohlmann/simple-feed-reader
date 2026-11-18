<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\UserRepository;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds, for every reader active in the last ACTIVE_DAYS, each window whose snapshot is missing or as old as its
 * interval and whose feeds have news since; the worker and the tick call it.
 * A build that finds another running is skipped and stays due for the next sweep.
 */
final readonly class TrendingRebuild
{
    public const int ACTIVE_DAYS = 14;

    public function __construct(
        private SnapshotBuilderInterface $builder,
        private TrendingSnapshotRepository $snapshots,
        private TrendingEntryRepository $entries,
        private UserRepository $users,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function rebuildDue(): TrendingRebuildReportModel
    {
        return $this->rebuildDueWhere(static fn (): bool => true);
    }

    /** Starts a due build only while time is left for it: at least as much as its last build took, or its estimate. */
    public function rebuildDueBefore(\DateTimeImmutable $deadline): TrendingRebuildReportModel
    {
        return $this->rebuildDueWhere(
            function (TrendingLastBuildsModel $lastBuilds, TrendingWindow $window) use ($deadline): bool {
                $remaining = $this->remainingMilliseconds($deadline);

                return $remaining > 0 && $remaining >= $lastBuilds->expectedDurationMilliseconds($window);
            },
        );
    }

    /** @param \Closure(TrendingLastBuildsModel, TrendingWindow): bool $mayStart asked just before each due build */
    private function rebuildDueWhere(\Closure $mayStart): TrendingRebuildReportModel
    {
        $builds = [];
        $since = $this->clock->now()->modify(\sprintf('-%d days', self::ACTIVE_DAYS));
        foreach ($this->users->trendingReaderIdsLoggedInSince($since) as $userId) {
            $lastBuilds = $this->snapshots->lastBuilds($userId);
            foreach (TrendingWindow::cases() as $window) {
                if (!$this->isDue($userId, $lastBuilds, $window) || !$mayStart($lastBuilds, $window)) {
                    continue;
                }
                $build = $this->buildUnlessBusy($userId, $window);
                if ($build !== null) {
                    $builds[] = $build;
                }
            }
        }

        return new TrendingRebuildReportModel($builds);
    }

    private function buildUnlessBusy(int $userId, TrendingWindow $window): ?TrendingBuildReportModel
    {
        try {
            return $this->builder->build($userId, $window);
        } catch (TrendingBuildBusyException) {
            $this->logger->debug(
                'Trending build skipped: another build is running.',
                ['user' => $userId, 'window' => $window->value],
            );

            return null;
        }
    }

    private function isDue(int $userId, TrendingLastBuildsModel $lastBuilds, TrendingWindow $window): bool
    {
        $dueAt = $lastBuilds->dueAt($window);
        if ($dueAt === null) {
            return true;
        }

        return $this->clock->now()->getTimestamp() >= $dueAt->getTimestamp()
            && $this->entries->hasEntriesCreatedAfter($userId, $lastBuilds->builtAt($window) ?? $dueAt);
    }

    private function remainingMilliseconds(\DateTimeImmutable $deadline): int
    {
        return intdiv((int) $deadline->format('Uu') - (int) $this->clock->now()->format('Uu'), 1_000);
    }
}
