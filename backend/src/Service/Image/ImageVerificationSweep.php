<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Repository\PendingImageVerificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A capped batch of pending images per tick, bounded by a wall-clock budget
 * so a run of dead hosts can't overrun the tick.
 */
final readonly class ImageVerificationSweep
{
    private const int MAX_PER_TICK = 25;
    private const int BUDGET_SECONDS = 15;

    public function __construct(
        private PendingImageVerificationRepository $repository,
        private ImageVerifier $imageVerifier,
        private EntityManagerInterface $em,
    ) {
    }

    public function verifyDue(): ImageVerificationReport
    {
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $measured = 0;
        $dropped = 0;
        $retried = 0;
        $processed = 0;

        foreach ($this->repository->findPendingImageVerification(self::MAX_PER_TICK) as $entry) {
            if (microtime(true) >= $deadline) {
                break;
            }
            match ($this->imageVerifier->verify($entry->getImage())) {
                ImageVerifyOutcome::Measured => $measured++,
                ImageVerifyOutcome::Dropped => $dropped++,
                ImageVerifyOutcome::Retried => $retried++,
            };
            $processed++;
        }

        if ($processed > 0) {
            $this->em->flush();
        }

        return new ImageVerificationReport($measured, $dropped, $retried);
    }
}
