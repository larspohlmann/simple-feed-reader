<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Image\ImageVerificationSweep;
use App\Service\Worker\Message\VerifyPendingImages;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class VerifyPendingImagesHandler
{
    public function __construct(
        private ImageVerificationSweep $imageVerificationSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(VerifyPendingImages $message): void
    {
        $report = $this->imageVerificationSweep->verifyDue();
        $this->logger->info('Worker image verification sweep finished.', ['report' => $report->toLogContext()]);
    }
}
