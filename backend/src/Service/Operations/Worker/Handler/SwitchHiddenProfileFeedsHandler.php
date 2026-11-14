<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Social\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Operations\Worker\Message\SwitchHiddenProfileFeeds;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SwitchHiddenProfileFeedsHandler
{
    private const int CALLS = 1;

    public function __construct(
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SwitchHiddenProfileFeeds $message): void
    {
        $report = $this->hiddenProfileSwitchSweep->sweep(ProfileCallBudgetModel::calls(self::CALLS));
        $this->logger->info('Worker hidden-profile switch finished.', ['report' => $report->toLogContext()]);
    }
}
