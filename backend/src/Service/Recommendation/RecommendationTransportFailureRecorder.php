<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The transport-failure ending every phase's provider call shares: one ceiling
 * increment, failing the run outright once it is reached. Guarded like every
 * banking write, for the same reason: the counter and the fail() the ceiling
 * triggers are the run's own state, and a tick that may no longer write must
 * write none of it (#439). The entity cannot refuse it --
 * RecommendationRun::recordTransportFailure() judges the status this tick read
 * before the call, so a run another process has since completed is failed
 * over it.
 *
 * Nothing is swallowed while the lock is held and the run is live: the guard
 * cannot throw there, and the caller's re-throw carries the provider's error
 * out. When it does throw, this tick has stopped owning the run, and tick()
 * answers with the state its real owner wrote.
 *
 * Lifted out of RecommendationRunAdvancer the same way RecommendationRunDeferral
 * was (#947 final review): one more ending, one more seam PHPMD's
 * ExcessiveClassComplexity forced out once the batch phase gained its own
 * halve-and-defer/halve-and-strike branches alongside distillation's and
 * consolidation's.
 */
final readonly class RecommendationTransportFailureRecorder
{
    public function __construct(
        private RecommendationTickCheckpoint $checkpoint,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function record(RecommendationRun $run, AiProviderSettings $settings, string $failureDetail): void
    {
        $this->checkpoint->guard($run);

        $run->recordTransportFailure();
        if ($run->hasExhaustedTransportRetries()) {
            // The real per-call detail, not a hardcoded "could not be reached":
            // most transport failures are the provider refusing or truncating a
            // call it received, and one flat unreachable message hid a fixable
            // 400 behind a network story (#329). Base URL stays: which endpoint failed.
            $run->fail(
                sprintf('The AI provider at %s failed: %s', $settings->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }
}
