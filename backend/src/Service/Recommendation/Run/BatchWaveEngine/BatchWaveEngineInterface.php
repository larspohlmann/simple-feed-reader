<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\BatchWaveEngine;

use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\Model\BatchReplyVerdictModel;
use App\Service\Recommendation\Run\Pass\BatchCall;

/**
 * An engine's part in a batch wave: it opens, sends and judges the calls; BatchWaveRounds runs the rounds around them.
 *
 * @template TWave of BatchWaveInterface
 * @template TRequest of object
 * @template TOutcome of BatchCallOutcomeInterface
 */
interface BatchWaveEngineInterface
{
    /**
     * Builds the request for the batch at this position and opens its run-log row.
     *
     * @param TWave $wave
     *
     * @return BatchCall<TRequest>
     */
    public function open(BatchWaveInterface $wave, int $position): BatchCall;

    /**
     * @param TWave                               $wave
     * @param non-empty-list<BatchCall<TRequest>> $calls
     *
     * @return RateLimitedResultModel<TOutcome> one outcome per call, aligned by index
     */
    public function sendAll(BatchWaveInterface $wave, array $calls): RateLimitedResultModel;

    /**
     * Asked only once no call of the round failed.
     *
     * @param TWave    $wave
     * @param TOutcome $outcome the reply to the batch at this position
     */
    public function judge(
        BatchWaveInterface $wave,
        int $position,
        BatchCallOutcomeInterface $outcome,
    ): BatchReplyVerdictModel;
}
