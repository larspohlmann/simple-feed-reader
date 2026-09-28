<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Factory;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use Symfony\Component\Clock\ClockInterface;

final readonly class RecommendationRunLogFactory
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private ClockInterface $clock,
    ) {
    }

    public function create(
        RecommendationRun $run,
        CallSlotModel $slot,
        CompletionRequestModel $request,
    ): RecommendationRunLog {
        return new RecommendationRunLog(
            $run,
            $slot->phase,
            $slot->batchNumber,
            $this->nextAttempt($run, $slot),
            self::renderedRequest($request),
            $this->clock->now(),
        );
    }

    /** Derived from the rows already recorded, so the recorder cannot disagree with its own rows. */
    private function nextAttempt(RecommendationRun $run, CallSlotModel $slot): int
    {
        return $this->logs->countAttempts($run, $slot->phase, $slot->batchNumber) + 1;
    }

    /** Pretty-printed for the human the debug view exists for: the payload as sent, minus transport framing. */
    private static function renderedRequest(CompletionRequestModel $request): string
    {
        return json_encode(
            ['model' => $request->model, 'messages' => $request->messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
