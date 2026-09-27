<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;

/** The cross-tick retry distillation and consolidation share; the batch phase retries inside its tick (#344). */
final readonly class InvalidReplyRetry
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param \Closure(): RecommendationRunReport $onAttemptsExhausted the degraded ending */
    public function retryOrDegrade(
        RecommendationRun $run,
        string $invalidReply,
        \Closure $onAttemptsExhausted,
    ): RecommendationRunReport {
        $run->recordInvalidReply($invalidReply);
        if ($run->progress()->attemptsExhausted) {
            return $onAttemptsExhausted();
        }
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}
