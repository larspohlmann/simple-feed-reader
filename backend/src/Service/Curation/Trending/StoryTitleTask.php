<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Curation\StoryAi\Model\StoryAiOutcomeModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Curation\StoryAi\StoryAiCalls;
use App\Service\Curation\StoryAi\StoryAiTask\StoryAiTaskInterface;
use App\Service\Curation\StoryAi\StorySettingsProvider;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
use App\Service\Curation\Trending\Model\StoryHeadlineModel;
use App\Service\Curation\Trending\Support\StoryTitlePrompt;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Psr\Log\LoggerInterface;

/** Writes each shown story's title and synopsis, at most once an hour and again once it grew by half. */
final readonly class StoryTitleTask implements StoryAiTaskInterface
{
    private const int HEADLINES = 12;

    private const string RETRY_AFTER = '-1 hour';

    public function __construct(
        private StorySettingsProvider $storySettings,
        private TrendingStoryRepository $stories,
        private ReaderWindows $readerWindows,
        private HeadlineMarkers $markers,
        private StoryAiCalls $calls,
        private StoryTitleParser $parser,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function task(): StoryAiTask
    {
        return StoryAiTask::Title;
    }

    public function connectionFor(User $user): ?AiProviderSettings
    {
        return $this->storySettings->titleConnectionFor($user);
    }

    /** Ranks the reader's windows only when a story a window could show is due at all. */
    public function dueStoryIds(User $user, \DateTimeImmutable $now): array
    {
        $due = $this->stories->findStoriesDueForTitle(
            $user->requireId(),
            $now->modify(self::RETRY_AFTER),
            self::earliestWindowStart($now),
        );
        if ($due === []) {
            return [];
        }

        return array_values(array_intersect($due, $this->shownStoryIdsOf($user->requireId())));
    }

    /** @return StoryAiOutcomeModel<mixed> */
    public function run(StoryAiPass $pass, int $storyId): StoryAiOutcomeModel
    {
        $headlines = $this->cleanedHeadlinesOf($storyId);
        if ($headlines === []) {
            $this->stories->recordTitleAttempt($storyId, $this->clock->now());

            return StoryAiOutcomeModel::unusable();
        }

        $outcome = $this->calls->complete(
            $pass,
            CallPromptModel::storyTitle(StoryTitlePrompt::messages($headlines, $pass->user->getLocale())),
            $this->parser->parse(...),
        );
        $this->record($storyId, $outcome);

        return $outcome;
    }

    /** @param StoryAiOutcomeModel<StoryHeadingModel> $outcome */
    private function record(int $storyId, StoryAiOutcomeModel $outcome): void
    {
        if ($outcome->isUsable()) {
            $this->stories->recordHeading($storyId, $outcome->value(), $this->clock->now());

            return;
        }

        $this->stories->recordTitleAttempt($storyId, $this->clock->now());
        if ($outcome->isFailed()) {
            $this->logger->warning(
                'Story title call failed.',
                ['story' => $storyId, 'exception' => $outcome->failure()],
            );
        }
    }

    /** @return list<StoryHeadlineModel> */
    private function cleanedHeadlinesOf(int $storyId): array
    {
        return array_map(
            fn (StoryHeadlineModel $headline): StoryHeadlineModel => $headline->cleaned(
                $this->markers->clean($headline->headline, $headline->feedTitle, $headline->language)->text,
            ),
            $this->stories->findMemberHeadlines($storyId, self::HEADLINES),
        );
    }

    private static function earliestWindowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return min(array_map(
            static fn (TrendingWindow $window): \DateTimeImmutable => $window->startBefore($now),
            TrendingWindow::cases(),
        ));
    }

    /** @return list<int> */
    private function shownStoryIdsOf(int $userId): array
    {
        return array_merge([], ...array_map(
            fn (TrendingWindow $window): array => RankedStoryModel::storyIdsOf(
                $this->readerWindows->shownStories($userId, $window),
            ),
            TrendingWindow::cases(),
        ));
    }
}
