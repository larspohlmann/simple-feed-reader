<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Image\ImageVerificationSweep;
use App\Service\Worker\Message\VerifyPendingImages;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class VerifyPendingImagesHandler
{
    private const int PASS_SECONDS = 15;

    public function __construct(
        private ImageVerificationSweep $imageVerificationSweep,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(VerifyPendingImages $message): void
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::PASS_SECONDS));
        $report = $this->imageVerificationSweep->verifyDueBefore($deadline);
        $this->logger->info('Worker image verification sweep finished.', ['report' => $report->toLogContext()]);
    }
}
