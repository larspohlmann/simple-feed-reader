<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;
use App\Service\Ingestion\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Operations\Worker\Message\RefreshDueFeeds;
use App\Service\Operations\Worker\RefreshWarmUp;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Delegates straight to RefreshRunner: nothing here re-derives `remaining` or second-guesses the runner's report. */
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
        private RefreshWarmUp $warmUp,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshDueFeeds $message): void
    {
        $request = RefreshRequestModel::allDue(self::BUDGET_SECONDS)->limitedTo($this->warmUp->nextBatchLimit());
        $report = $this->refreshRunner->run($request);

        // 'busy' is healthy here: a user-driven refresh holds the global lock
        // and is doing the same work; this firing simply yields to it.
        $this->logger->info('Worker refresh sweep finished.', ['report' => $report->toLogContext()]);
    }
}
