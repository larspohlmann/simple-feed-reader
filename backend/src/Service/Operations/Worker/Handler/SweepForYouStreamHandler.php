<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\Recommendation\Stream\ForYouStreamSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Operations\Worker\Message\SweepForYouStream;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepForYouStreamHandler
{
    private const int BUDGET_SECONDS = 60;

    public function __construct(
        private ForYouStreamSweep $sweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepForYouStream $message): void
    {
        $report = $this->sweep->sweep(SweepBudgetModel::seconds(self::BUDGET_SECONDS));
        $this->logger->info('Worker For you stream sweep finished.', ['report' => $report->toLogContext()]);
    }
}
