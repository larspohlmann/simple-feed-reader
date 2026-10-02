<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\BatchCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;

/**
 * An engine whose rounds a test queues: each send answers with the next queued result or throws the next queued error.
 * A reply starting with "usable" wins its batch's first id.
 *
 * @implements BatchWaveEngineInterface<ScriptedBatchWave, WaveBatchModel, ScriptedBatchOutcome>
 */
final class ScriptedBatchWaveEngine implements BatchWaveEngineInterface
{
    public const int SCORE = 80;

    /** @var list<RateLimitedResultModel<ScriptedBatchOutcome>|\Throwable> */
    private array $rounds = [];

    /** @var list<list<int>> */
    private array $sentRounds = [];

    /** @var array<int, \Throwable> batch index => what opening it throws */
    private array $openFailures = [];

    public function __construct(private readonly RecommendationCallRecorder $callRecorder)
    {
    }

    /** @param RateLimitedResultModel<ScriptedBatchOutcome>|\Throwable $round */
    public function queueRound(RateLimitedResultModel|\Throwable $round): void
    {
        $this->rounds[] = $round;
    }

    public function failOpening(int $batchIndex, \Throwable $failure): void
    {
        $this->openFailures[$batchIndex] = $failure;
    }

    /** @return list<list<int>> the batch indexes each send carried */
    public function sentRounds(): array
    {
        return $this->sentRounds;
    }

    /**
     * @param ScriptedBatchWave $wave
     *
     * @return BatchCall<WaveBatchModel>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall
    {
        $waveBatch = $wave->batches()[$position];
        if (isset($this->openFailures[$waveBatch->index])) {
            throw $this->openFailures[$waveBatch->index];
        }
        $recordedCall = $this->callRecorder->begin(
            $wave->tick()->run,
            CallSlotModel::batch($waveBatch->index + 1),
            'batch ' . $waveBatch->index,
        );

        return new BatchCall($waveBatch, $recordedCall);
    }

    /**
     * @param ScriptedBatchWave                         $wave
     * @param non-empty-list<BatchCall<WaveBatchModel>> $calls
     *
     * @return RateLimitedResultModel<ScriptedBatchOutcome>
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel
    {
        $this->sentRounds[] = array_map(static fn (BatchCall $call): int => $call->request->index, $calls);
        $round = array_shift($this->rounds) ?? throw new \LogicException('No round is queued.');
        if ($round instanceof \Throwable) {
            throw $round;
        }

        return $round;
    }

    /**
     * @param ScriptedBatchWave    $wave
     * @param ScriptedBatchOutcome $outcome
     */
    public function judge(
        BatchWaveInterface $wave,
        int $position,
        BatchCallOutcomeInterface $outcome,
    ): BatchReplyVerdictModel {
        if (!str_starts_with($outcome->reply, 'usable')) {
            return BatchReplyVerdictModel::unusable($outcome->reply);
        }
        $winner = ['id' => $wave->batches()[$position]->ids[0], 'score' => self::SCORE, 'reason' => ''];

        return BatchReplyVerdictModel::usable([$winner], $outcome->reply);
    }
}
