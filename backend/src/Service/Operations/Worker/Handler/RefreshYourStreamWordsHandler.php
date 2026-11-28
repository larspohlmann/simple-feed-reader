<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\YourStream\YourStreamWordSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Operations\Worker\Message\RefreshYourStreamWords;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshYourStreamWordsHandler
{
    public function __construct(
        private YourStreamWordSweep $yourStreamWords,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshYourStreamWords $message): void
    {
        $report = $this->yourStreamWords->sweep(
            SweepBudgetModel::seconds(YourStreamWordSweep::UNHURRIED_BUDGET_SECONDS),
        );
        $this->logger->info('Worker your-stream words refresh finished.', ['report' => $report->toLogContext()]);
    }
}
