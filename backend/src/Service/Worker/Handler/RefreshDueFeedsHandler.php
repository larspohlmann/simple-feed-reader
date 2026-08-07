<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Service\Worker\Message\RefreshDueFeeds;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The 2026-08-07 decision that brings scheduled refresh to worker-equipped
 * installs (#311); poll-only (Strato) installs stay manual. Fires every five
 * minutes and delegates straight to RefreshRunner — nothing here re-derives
 * `remaining` or otherwise second-guesses the runner's own report.
 */
#[AsMessageHandler]
final readonly class RefreshDueFeedsHandler
{
    /**
     * Generous compared with the HTTP endpoints' 20-25 s: the worker has no
     * FastCGI window to fit. Feeds the budget skips are picked up by the next
     * five-minute firing, so the cap only bounds one firing's work.
     */
    private const int BUDGET_SECONDS = 120;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshDueFeeds $message): void
    {
        $report = $this->refreshRunner->run(RefreshRequest::allDue(self::BUDGET_SECONDS));

        // 'busy' is healthy here: a user-driven refresh holds the global lock
        // and is doing the same work; this firing simply yields to it.
        $this->logger->info('Worker refresh sweep finished.', ['report' => $report->toArray()]);
    }
}
