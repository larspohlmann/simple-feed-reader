<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The deferral ending distillTick and consolidateTick share (#947): a 429
 * neither phase's driver will wait out records "retry not before now plus the
 * wait" and returns at once. No strike -- a deferral is a wait, not a
 * failure, unlike the transport-failure ceiling ProviderUnreachableException
 * and CredentialsRejectedException count against in RecommendationRunAdvancer.
 * Lifted out the same way RecommendationRunFinalizer was (#338): one more
 * ending, one more seam the tests drive independently.
 */
final readonly class RecommendationRunDeferral
{
    public function __construct(
        private RecommendationTickCheckpoint $checkpoint,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function defer(
        RecommendationRun $run,
        RecommendationRunRateLimitedException $rateLimited,
    ): RecommendationRunReport {
        $this->checkpoint->guard($run);
        $run->deferRetryUntil($this->clock->now()->add(self::waitInterval($rateLimited->waitSeconds())));
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    private static function waitInterval(float $seconds): \DateInterval
    {
        return new \DateInterval('PT' . max(0, (int) ceil($seconds)) . 'S');
    }
}
