<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\YourStream\YourStreamSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Operations\Worker\Message\SweepYourStream;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepYourStreamHandler
{
    private const int BUDGET_SECONDS = 60;

    public function __construct(
        private YourStreamSweep $sweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepYourStream $message): void
    {
        $report = $this->sweep->sweep(SweepBudgetModel::seconds(self::BUDGET_SECONDS));
        $this->logger->info('Worker Your stream sweep finished.', ['report' => $report->toLogContext()]);
    }
}
