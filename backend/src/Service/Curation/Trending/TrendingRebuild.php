<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\UserRepository;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\DueTrendingBuildModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Persistence\ManagerReopener;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds, for every active Trending reader, each window whose snapshot is missing or as old as its interval and
 * whose feeds have news since, stalest first across readers; the worker and the tick call it.
 * A build that finds another running, or loses a lock wait or a deadlock, is skipped and stays due for the next sweep.
 */
final readonly class TrendingRebuild
{
    public function __construct(
        private SnapshotBuilderInterface $builder,
        private TrendingSnapshotRepository $snapshots,
        private TrendingEntryRepository $entries,
        private UserRepository $users,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
        private ManagerReopener $managerReopener,
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
        foreach ($this->dueStalestFirst() as $due) {
            if (!$mayStart($due->lastBuilds, $due->window)) {
                continue;
            }
            $build = $this->buildUnlessBusy($due->userId, $due->window);
            if ($build !== null) {
                $builds[] = $build;
            }
        }

        return new TrendingRebuildReportModel($builds);
    }

    /** @return list<DueTrendingBuildModel> */
    private function dueStalestFirst(): array
    {
        $due = [];
        foreach ($this->users->activeTrendingReaders($this->clock->now()) as $reader) {
            $userId = $reader->requireId();
            $lastBuilds = $this->snapshots->lastBuilds($userId);
            foreach (TrendingWindow::cases() as $window) {
                if ($this->isDue($userId, $lastBuilds, $window)) {
                    $due[] = new DueTrendingBuildModel($userId, $window, $lastBuilds);
                }
            }
        }
        usort(
            $due,
            static fn (DueTrendingBuildModel $left, DueTrendingBuildModel $right): int => $left->lastBuiltTimestamp()
                <=> $right->lastBuiltTimestamp(),
        );

        return $due;
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
        } catch (RetryableException $exception) {
            $this->logger->warning(
                'Trending build skipped: the database gave up a lock.',
                ['user' => $userId, 'window' => $window->value, 'exception' => $exception],
            );
            // The failed transaction closed the entity manager; every later build would fail on it.
            $this->managerReopener->ensureManagerOpen();

            return null;
        }
    }

    private function isDue(int $userId, TrendingLastBuildsModel $lastBuilds, TrendingWindow $window): bool
    {
        $builtAt = $lastBuilds->findBuiltAt($window);
        if ($builtAt === null) {
            return true;
        }

        return $this->clock->now()->getTimestamp() >= $builtAt->getTimestamp() + $window->rebuildIntervalSeconds()
            && $this->entries->hasEntriesCreatedAfter($userId, $builtAt);
    }

    private function remainingMilliseconds(\DateTimeImmutable $deadline): int
    {
        return intdiv((int) $deadline->format('Uu') - (int) $this->clock->now()->format('Uu'), 1_000);
    }
}
