<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Mail\Digest\SendDueDigests as SendDueDigestsService;
use App\Service\Worker\Message\SendDueDigests;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fires every hour and delegates straight to the sweep service (#636);
 * nothing here re-derives dueness or otherwise second-guesses the service's
 * own report.
 */
#[AsMessageHandler]
final readonly class SendDueDigestsHandler
{
    public function __construct(
        private SendDueDigestsService $sendDueDigests,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendDueDigests $message): void
    {
        $report = $this->sendDueDigests->run();

        $this->logger->info('Worker digest sweep finished.', ['report' => $report->toArray()]);
    }
}
