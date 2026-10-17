<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Worker\Message\SwitchHiddenProfileFeeds;
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
        $report = $this->hiddenProfileSwitchSweep->sweep(ProfileImageBudgetModel::calls(self::CALLS));
        $this->logger->info('Worker hidden-profile switch finished.', ['report' => $report->toLogContext()]);
    }
}
