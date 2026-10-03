<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\CallingRun;
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
        return $this->opened(
            $this->logFactory->create($run, $slot, $renderedRequest),
            CallingRun::recommendationRun($run->requireId()),
        );
    }

    public function beginForProfileRun(ProfileRun $profileRun, string $renderedRequest): RecordedCall
    {
        return $this->opened(
            $this->logFactory->createForProfileRun($profileRun, $renderedRequest),
            CallingRun::profileRun($profileRun->requireId()),
        );
    }

    private function opened(RecommendationRunLog $log, CallingRun $callingRun): RecordedCall
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new RecordedCall($this->calls, $this->clock, $callingRun, $log->requireId());
    }
}
