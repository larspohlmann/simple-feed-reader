<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\StoryAi\Model\StoryAiReportModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Curation\StoryAi\StoryAiTask\StoryAiTaskInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every story AI task over the due stories of each reader active in the last ACTIVE_DAYS, the same span as
 * TrendingRebuild's, which this module cannot name. A rate limit stops that reader until the next sweep.
 */
final readonly class StoryAiSweep
{
    public const int ACTIVE_DAYS = 14;

    private const string WITHOUT_DEADLINE = '+100 years';

    /** @param iterable<StoryAiTaskInterface> $tasks */
    public function __construct(
        #[AutowireIterator('app.story_ai_task')]
        private iterable $tasks,
        private UserRepository $users,
        private NaiveUtcClock $clock,
    ) {
    }

    public function runDue(): StoryAiReportModel
    {
        return $this->runDueBefore($this->clock->now()->modify(self::WITHOUT_DEADLINE));
    }

    /** Starts a story only before the deadline. */
    public function runDueBefore(\DateTimeImmutable $deadline): StoryAiReportModel
    {
        $report = StoryAiReportModel::none();
        foreach ($this->activeReaders() as $reader) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            $report = $report->plus($this->runFor($reader, $deadline));
        }

        return $report;
    }

    private function runFor(User $reader, \DateTimeImmutable $deadline): StoryAiReportModel
    {
        $report = StoryAiReportModel::none();
        foreach ($this->tasks as $task) {
            $connection = $task->connectionFor($reader);
            if ($connection === null) {
                continue;
            }
            $pass = new StoryAiPass($reader, $connection, $task->task(), $deadline);
            if ($pass->isPastDeadline($this->clock->now())) {
                return $report;
            }
            foreach ($this->dueStoriesBeforeTheDeadline($task, $pass) as $storyId) {
                try {
                    $report = $report->counting($task->run($pass, $storyId));
                } catch (ProviderRateLimitedException) {
                    return $report;
                }
            }
        }

        return $report;
    }

    /** @return \Generator<int, int> */
    private function dueStoriesBeforeTheDeadline(StoryAiTaskInterface $task, StoryAiPass $pass): \Generator
    {
        foreach ($task->dueStoryIds($pass->user, $this->clock->now()) as $storyId) {
            if ($pass->isPastDeadline($this->clock->now())) {
                return;
            }
            yield $storyId;
        }
    }

    /** @return \Generator<int, User> */
    private function activeReaders(): \Generator
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', self::ACTIVE_DAYS));
        foreach ($this->users->trendingReaderIdsLoggedInSince($since) as $userId) {
            $reader = $this->users->find($userId);
            if ($reader !== null) {
                yield $reader;
            }
        }
    }
}
