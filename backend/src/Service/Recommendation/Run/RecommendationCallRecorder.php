<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationCallRepository;
use App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the run-log row for one provider call the moment it is sent and hands back the RecordedCall that settles it.
 * Every run records, debug on or off: the log is the history the ETA reads.
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationCallRepository $calls,
        private ClockInterface $clock,
        private RecommendationRunLogFactory $logFactory,
    ) {
    }

    public function begin(RecommendationRun $run, CallSlotModel $slot, string $renderedRequest): RecordedCall
    {
        $log = $this->logFactory->create($run, $slot, $renderedRequest);
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new RecordedCall(
            $this->calls,
            $this->clock,
            $run->requireId(),
            $log->requireId(),
        );
    }
}
