<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Trending\TrendingRebuild;
use App\Service\Worker\Message\RebuildDueTrendingStories;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildDueTrendingStoriesHandler
{
    public function __construct(
        private TrendingRebuild $trendingRebuild,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RebuildDueTrendingStories $message): void
    {
        $report = $this->trendingRebuild->rebuildDue();
        $this->logger->info('Worker trending rebuild finished.', ['report' => $report->toLogContext()]);
    }
}
