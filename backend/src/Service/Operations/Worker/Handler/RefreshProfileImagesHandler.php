<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\ProfileImageSweep;
use App\Service\Worker\Message\RefreshProfileImages;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshProfileImagesHandler
{
    private const int CALLS = 2;

    public function __construct(
        private ProfileImageSweep $profileImageSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshProfileImages $message): void
    {
        $report = $this->profileImageSweep->sweep(ProfileCallBudgetModel::calls(self::CALLS));
        $this->logger->info('Worker profile picture sweep finished.', ['report' => $report->toLogContext()]);
    }
}
