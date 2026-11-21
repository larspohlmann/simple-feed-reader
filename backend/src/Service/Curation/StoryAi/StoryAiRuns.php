<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi;

use App\Entity\StoryAiRun;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\StoryAiRunRepository;
use App\Service\Curation\Ai\Support\ProviderHost;
use App\Service\Curation\Recommendation\Run\Support\RunLogRetention;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/** The run a pass's calls bill, opened on its first call so a pass that calls nothing leaves no row. */
final readonly class StoryAiRuns
{
    public function __construct(
        private StoryAiRunRepository $storyAiRuns,
        private RecommendationRunLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /** The pass's run, opening one when it has none and keeping call logs only for the newest RunLogRetention::RUNS. */
    public function openFor(StoryAiPass $pass): StoryAiRun
    {
        $run = $pass->run();
        if ($run !== null) {
            return $run;
        }

        $run = new StoryAiRun($pass->user, $pass->task, $this->clock->now());
        $run->stampProvider(ProviderHost::parseHost($pass->connection), $pass->connection->getModel());
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $pass->billTo($run);
        $this->logs->deleteForUserOutsideStoryAiRuns(
            $pass->user,
            $this->storyAiRuns->findNewestIdsForUser($pass->user, RunLogRetention::RUNS),
        );

        return $run;
    }
}
