<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The ending of a 429 the tick will not wait out (#947): retry not before now plus the wait. A deferral is a wait,
 * not a failure, so it strikes nothing against the transport-failure ceiling.
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
        ProviderRateLimitedException $rateLimited,
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
