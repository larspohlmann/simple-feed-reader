<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationCallRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Completion\CompletionRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the run-log row for one provider call the moment it is sent (#309) and hands back the RecordedCall that
 * watches its stream. Every run records, debug on or off: the log is the history the ETA reads (#638).
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private RecommendationCallRepository $calls,
        private ClockInterface $clock,
    ) {
    }

    public function begin(RecommendationRun $run, CallSlot $slot, CompletionRequest $request): RecordedCall
    {
        $log = $this->persistedLog($run, $slot, $request);

        return new RecordedCall(
            $this->calls,
            $this->clock,
            $run->requireId(),
            $log->getId(),
        );
    }

    private function persistedLog(
        RecommendationRun $run,
        CallSlot $slot,
        CompletionRequest $request,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $slot->phase,
            $slot->batchNumber,
            $this->nextAttempt($run, $slot),
            self::renderedRequest($request),
            $this->clock->now(),
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    /** Derived from the rows already recorded, so the recorder cannot disagree with its own rows. */
    private function nextAttempt(RecommendationRun $run, CallSlot $slot): int
    {
        return $this->logs->countAttempts($run, $slot->phase, $slot->batchNumber) + 1;
    }

    /** Pretty-printed for the human the debug view exists for: the payload as sent, minus transport framing. */
    private static function renderedRequest(CompletionRequest $request): string
    {
        return json_encode(
            ['model' => $request->model, 'messages' => $request->messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
