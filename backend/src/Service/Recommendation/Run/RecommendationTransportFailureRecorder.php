<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The transport-failure ending every phase shares: one strike, and the run fails at MAX_TRANSPORT_FAILURES. Guarded
 * like every banking write: getRunningCallAttempts() judges the status this tick read before the call, so without
 * the guard a run another process completed meanwhile would be failed over it.
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

        $run->getRunningCallAttempts()->recordTransportFailure();
        if ($run->hasExhaustedTransportRetries()) {
            // The call's own detail, not a flat "unreachable", which hid a fixable 400 behind a network story (#329).
            $run->fail(
                sprintf('The AI provider at %s failed: %s', $settings->getBaseUrl(), $failureDetail),
                $this->clock->now(),
            );
        }
        $this->entityManager->flush();
    }
}
