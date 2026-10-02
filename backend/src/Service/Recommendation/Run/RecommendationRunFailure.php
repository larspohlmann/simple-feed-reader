<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class RecommendationRunFailure
{
    public function __construct(
        private RecommendationTickCheckpoint $checkpoint,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(RecommendationRun $run, string $message): RecommendationRunReportModel
    {
        $this->checkpoint->guard($run);
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
