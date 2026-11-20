<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\SnapshotBuilder;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Pass\BuildStopwatch;
use App\Service\Curation\Trending\StoryBuildPolicy\StoryBuildPolicyInterface;
use App\Service\Curation\Trending\TrendingGrouping;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Groups one window of one user's feeds and matches the groups to that user's stored stories, updating them in place in
 * one transaction: the reader sees the old stories until the commit. Only the week build merges and prunes (#2128).
 * One build at a time per user across both windows: overlapping in-place updates collide.
 */
final readonly class TrendingSnapshotBuilder implements SnapshotBuilderInterface
{
    public const string LOCK_NAME = 'trending-build';

    private const float LOCK_TTL_SECONDS = 600.0;

    public function __construct(
        private TrendingGrouping $grouping,
        private TrendingEntryRepository $windowEntries,
        #[AutowireLocator('app.trending_story_build_policy')]
        private ContainerInterface $policies,
        private TrendingStoryHistoryRepository $history,
        private TrendingSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LockFactory $lockFactory,
    ) {
    }

    /** @throws TrendingBuildBusyException while another build holds the lock */
    public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME . '-' . $userId, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            throw new TrendingBuildBusyException();
        }

        try {
            return $this->buildLocked($userId, $window, $lock);
        } finally {
            $lock->release();
        }
    }

    private function buildLocked(int $userId, TrendingWindow $window, LockInterface $lock): TrendingBuildReportModel
    {
        $stopwatch = BuildStopwatch::start();
        $builtAt = $this->clock->now();
        $run = $this->grouping->collect($userId, $window);
        $groups = $this->grouping->stories($run);
        $entryCount = $run->entryCount();
        $build = new StoryBuildModel($userId, $window, $run->since, $builtAt);
        $activeFeedCount = $this->windowEntries->activeFeedCount($userId, $build->since);
        $policy = $this->selectPolicy($window);
        $stored = $this->history->storedInWindow($build->userId, $build->since);
        $match = $policy->match($run, $groups, $stored);
        unset($run, $stored);

        $this->refreshLock($lock);

        return $this->entityManager->wrapInTransaction(function () use (
            $build,
            $policy,
            $match,
            $entryCount,
            $activeFeedCount,
            $stopwatch,
        ) {
            $pruned = $this->write($build, $policy, $match);
            $report = new TrendingBuildReportModel(
                $build->userId,
                $build->window,
                $build->builtAt,
                $entryCount,
                $activeFeedCount,
                \count($match->assignments),
                $stopwatch->elapsedMilliseconds(),
                $stopwatch->peakMemoryBytes(),
                $match,
                $pruned,
            );
            $this->snapshots->recordSnapshot($report);

            return $report;
        });
    }

    private function refreshLock(LockInterface $lock): void
    {
        try {
            $lock->refresh();
        } catch (LockExceptionInterface $exception) {
            throw new TrendingBuildBusyException(previous: $exception);
        }
    }

    /** @return int the stories pruned */
    private function write(StoryBuildModel $build, StoryBuildPolicyInterface $policy, StoryMatchModel $match): int
    {
        foreach ($match->merges as $merge) {
            $this->history->merge($merge);
        }
        $this->history->place($build, $this->history->saveAll($build, $match->assignments));

        return $policy->prune($build);
    }

    private function selectPolicy(TrendingWindow $window): StoryBuildPolicyInterface
    {
        try {
            $policy = $this->policies->get($window->value);
        } catch (ContainerExceptionInterface $exception) {
            throw new \LogicException(
                \sprintf('No story build policy is wired for the "%s" window.', $window->value),
                previous: $exception,
            );
        }
        \assert($policy instanceof StoryBuildPolicyInterface);

        return $policy;
    }
}
