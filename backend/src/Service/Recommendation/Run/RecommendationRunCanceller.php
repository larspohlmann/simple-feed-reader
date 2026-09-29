<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Stops the account's active run without taking the lock, since a tick may sit in a provider call for minutes. The
 * status flips now and RecommendationTickCheckpoint makes that tick drop its result; the call in flight is paid for.
 */
final readonly class RecommendationRunCanceller
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NoActiveRecommendationRunException when nothing is pending or running */
    public function cancel(User $user): void
    {
        $run = $this->runs->findActiveForUser($user) ?? throw new NoActiveRecommendationRunException();

        $run->cancel($this->clock->now());
        $this->entityManager->flush();
    }
}
