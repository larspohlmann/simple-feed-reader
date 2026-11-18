<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\StoryAi\StoryAiSweep;
use App\Service\Operations\Worker\Message\RunDueStoryAiTasks;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunDueStoryAiTasksHandler
{
    public function __construct(
        private StoryAiSweep $storyAiSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunDueStoryAiTasks $message): void
    {
        $report = $this->storyAiSweep->runDue();
        $this->logger->info('Worker story AI tasks finished.', ['report' => $report->toLogContext()]);
    }
}
