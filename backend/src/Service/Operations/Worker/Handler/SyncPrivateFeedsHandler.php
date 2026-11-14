<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\PrivateFeedSyncSweep;
use App\Service\Worker\Message\SyncPrivateFeeds;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPrivateFeedsHandler
{
    private const int ACCOUNTS = 10;

    public function __construct(
        private PrivateFeedSyncSweep $privateFeedSyncSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncPrivateFeeds $message): void
    {
        $report = $this->privateFeedSyncSweep->sweep(PrivateFeedSyncBudgetModel::accounts(self::ACCOUNTS));
        $this->logger->info('Worker private feed sync finished.', ['report' => $report->toLogContext()]);
    }
}
