<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Repository\PendingImageVerificationRepository;
use App\Service\Image\Model\ImageVerificationReportModel;
use App\Service\Image\Model\ImageVerifyOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * A capped batch of pending images per pass, bounded by the caller's deadline
 * so a run of dead hosts can't overrun the tick.
 */
final readonly class ImageVerificationSweep
{
    private const int MAX_PER_PASS = 100;

    public function __construct(
        private PendingImageVerificationRepository $pendingVerifications,
        private ImageVerifier $imageVerifier,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function verifyDueBefore(\DateTimeImmutable $deadline): ImageVerificationReportModel
    {
        $measured = 0;
        $kept = 0;
        $dropped = 0;
        $retried = 0;

        foreach ($this->pendingVerifications->findPendingImageVerification(self::MAX_PER_PASS) as $entry) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            match ($this->imageVerifier->verify($entry)) {
                ImageVerifyOutcome::Measured => $measured++,
                ImageVerifyOutcome::Kept => $kept++,
                ImageVerifyOutcome::Dropped => $dropped++,
                ImageVerifyOutcome::Retried => $retried++,
            };
            $this->entityManager->flush();
        }

        return new ImageVerificationReportModel($measured, $kept, $dropped, $retried);
    }
}
