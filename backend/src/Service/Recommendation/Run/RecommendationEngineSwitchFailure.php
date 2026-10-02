<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * A run belongs to the engine whose batches it froze. Failed, not cancelled: the error says why, and switching back
 * to that connection makes the run resumable where it stopped.
 */
final readonly class RecommendationEngineSwitchFailure
{
    public const string MESSAGE = 'This run was started with a different recommendation engine than the active AI '
        . 'connection uses. Start a new run, or switch back to that connection to resume this one.';

    public function __construct(
        private RecommendationTickCheckpoint $checkpoint,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(RecommendationRun $run): RecommendationRunReportModel
    {
        $this->checkpoint->guard($run);
        $run->fail(self::MESSAGE, $this->clock->now());
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
