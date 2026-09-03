<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Exception\NoActiveRecommendationRunException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Stops the account's active run at the user's request.
 *
 * Deliberately takes no lock: a tick may sit in a provider call for minutes,
 * and waiting would make the stop button feel broken that whole time. So the
 * status flips immediately; the running tick re-reads it and abandons its own
 * result rather than flush over this one (RecommendationRunAdvancer).
 *
 * Stopping does not cancel the outbound HTTP request already in flight; that
 * spend is committed. The stop prevents every call after it.
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
