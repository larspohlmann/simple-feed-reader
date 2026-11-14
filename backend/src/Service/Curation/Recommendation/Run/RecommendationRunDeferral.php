<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The ending of a 429 the tick will not wait out: retry not before now plus the wait. A deferral is a wait, not a
 * failure, so it strikes nothing against the transport-failure ceiling.
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
    ): RecommendationRunReportModel {
        $this->checkpoint->guard($run);
        $retryNotBefore = $this->clock->now()->add(self::waitInterval($rateLimited->waitSeconds()));
        $run->getRunningThrottle()->deferUntil($retryNotBefore);
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }

    private static function waitInterval(float $seconds): \DateInterval
    {
        return new \DateInterval('PT' . max(0, (int) ceil($seconds)) . 'S');
    }
}
