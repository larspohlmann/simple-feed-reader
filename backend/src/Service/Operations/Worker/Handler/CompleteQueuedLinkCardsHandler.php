<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Worker\Message\CompleteQueuedLinkCards;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteQueuedLinkCardsHandler
{
    private const int FETCHES = 5;

    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteQueuedLinkCards $message): void
    {
        $report = $this->linkCardSweep->sweep(LinkCardBudgetModel::fetches(self::FETCHES));
        $this->logger->info('Worker link-card sweep finished.', ['report' => $report->toLogContext()]);
    }
}
