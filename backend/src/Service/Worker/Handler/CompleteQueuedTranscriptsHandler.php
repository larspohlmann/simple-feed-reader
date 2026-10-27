<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\TranscriptSweep;
use App\Service\Worker\Message\CompleteQueuedTranscripts;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteQueuedTranscriptsHandler
{
    private const int FETCHES = 5;

    public function __construct(
        private TranscriptSweep $transcriptSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteQueuedTranscripts $message): void
    {
        $report = $this->transcriptSweep->sweep(TranscriptBudgetModel::fetches(self::FETCHES));
        $this->logger->info('Worker transcript sweep finished.', ['report' => $report->toLogContext()]);
    }
}
