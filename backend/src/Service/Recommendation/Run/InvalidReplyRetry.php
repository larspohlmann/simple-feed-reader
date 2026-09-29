<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** The cross-tick retry distillation and consolidation share; the batch phase retries inside its tick. */
final readonly class InvalidReplyRetry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param \Closure(): RecommendationRunReportModel $onAttemptsExhausted the degraded ending */
    public function retryOrDegrade(
        RecommendationRun $run,
        string $invalidReply,
        \Closure $onAttemptsExhausted,
    ): RecommendationRunReportModel {
        $run->getRunningCallAttempts()->recordInvalidReply($invalidReply);
        if ($run->getProgress()->attemptsExhausted) {
            return $onAttemptsExhausted();
        }
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
