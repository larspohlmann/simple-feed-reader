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
 * Deliberately takes no lock. A tick may be inside a provider call that runs
 * for minutes, and waiting for it would make the stop button feel broken for
 * exactly as long as the thing the user wants stopped keeps running. So the
 * status flips immediately and the running tick is expected to notice: it
 * re-reads the status after its call and abandons its own result rather than
 * flushing it over this one (RecommendationRunAdvancer).
 *
 * The consequence to keep in mind is that stopping does not cancel the
 * outbound HTTP request already in flight. That spend is committed; what the
 * stop prevents is every call after it.
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
