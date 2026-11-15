<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Operations\Worker\Message\ResolvePendingLinks;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResolvePendingLinksHandler
{
    private const int LINKS = 5;

    public function __construct(
        private LinkResolutionSweep $linkResolutionSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolvePendingLinks $message): void
    {
        $report = $this->linkResolutionSweep->resolvePending(LinkResolutionBudgetModel::links(self::LINKS));
        $this->logger->info('Worker link resolution finished.', ['report' => $report->toLogContext()]);
    }
}
