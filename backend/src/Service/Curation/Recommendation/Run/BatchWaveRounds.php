<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Recommendation\Run\BatchCallOutcome\BatchCallOutcomeInterface;
use App\Service\Recommendation\Run\BatchWave\BatchWaveInterface;
use App\Service\Recommendation\Run\BatchWaveEngine\BatchWaveEngineInterface;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Pass\BatchCall;
use App\Service\Recommendation\Run\Support\BatchWaveWinners;

/**
 * A batch wave's rounds, whatever the engine: an unusable batch retries alone up to MAX_ATTEMPTS rounds, then yields
 * no winners. A transport failure settles every open call and banks nothing, the atomic-wave rule; a deferring plan's
 * 429 throws ProviderRateLimitedException instead.
 */
final readonly class BatchWaveRounds
{
    private const string DEFERRAL_DETAIL = 'Provider rate limited; deferring.';

    public function __construct(private RecommendationTickCheckpoint $checkpoint)
    {
    }

    /**
     * @template TWave of BatchWaveInterface
     * @template TRequest of object
     * @template TOutcome of BatchCallOutcomeInterface
     *
     * @param BatchWaveEngineInterface<TWave, TRequest, TOutcome> $engine
     * @param TWave                                               $wave
     *
     * @throws \App\Service\Ai\Exception\ProviderRejectedRequestException
     * @throws \App\Service\Ai\Exception\ProviderUnreachableException
     * @throws \App\Service\Ai\Exception\CredentialsRejectedException
     * @throws \App\Service\Ai\Exception\RetryableProviderException
     * @throws ProviderRateLimitedException
     */
    public function resolve(BatchWaveEngineInterface $engine, BatchWaveInterface $wave): BatchWaveResultModel
    {
        $rateLimitObserved = false;
        [$winners, $pending] = BatchWaveWinners::splitByPruned($wave->batches());

        for ($round = 1; [] !== $pending; $round++) {
            $roundResult = $this->sendRound($engine, $wave, $pending);
            $rateLimitObserved = $rateLimitObserved || $roundResult['observed'];
            $pending = [];
            foreach ($roundResult['replies'] as $position => $reply) {
                $verdict = $engine->judge($wave, $position, $reply['outcome']);
                if ($verdict->usable) {
                    $reply['call']->recordedCall->finishUsable($verdict->transcript);
                    $winners[$position] = $verdict->winners;

                    continue;
                }
                $reply['call']->recordedCall->finishUnusable($verdict->transcript);
                $pending[] = $position;
            }

            $this->checkpoint->guard($wave->tick()->run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return new BatchWaveResultModel(BatchWaveWinners::degradeUnresolved($winners, $pending), $rateLimitObserved);
    }

    /**
     * @template TWave of BatchWaveInterface
     * @template TRequest of object
     * @template TOutcome of BatchCallOutcomeInterface
     *
     * @param BatchWaveEngineInterface<TWave, TRequest, TOutcome> $engine
     * @param TWave                                               $wave
     * @param non-empty-list<int>                                 $pending positions still awaiting a usable reply
     *
     * @return array{replies: array<int, array{call: BatchCall<TRequest>, outcome: TOutcome}>, observed: bool}
     */
    private function sendRound(BatchWaveEngineInterface $engine, BatchWaveInterface $wave, array $pending): array
    {
        $calls = self::openAll($engine, $wave, $pending);

        $result = self::sendAll($engine, $wave, $calls);
        if ($result->isDeferred()) {
            self::abortEvery($calls, self::DEFERRAL_DETAIL);

            throw new ProviderRateLimitedException($result->deferSeconds);
        }

        self::guardWaveTransport($calls, $result->outcomes);

        return [
            'replies' => self::repliesByPosition($pending, $calls, $result->outcomes),
            'observed' => $result->rateLimitObserved,
        ];
    }

    /**
     * A throw while opening settles the rows opened before it, so none reads as "still running".
     *
     * @template TWave of BatchWaveInterface
     * @template TRequest of object
     * @template TOutcome of BatchCallOutcomeInterface
     *
     * @param BatchWaveEngineInterface<TWave, TRequest, TOutcome> $engine
     * @param TWave                                               $wave
     * @param non-empty-list<int>                                 $pending
     *
     * @return non-empty-list<BatchCall<TRequest>>
     */
    private static function openAll(BatchWaveEngineInterface $engine, BatchWaveInterface $wave, array $pending): array
    {
        $calls = [];
        try {
            foreach ($pending as $position) {
                $calls[] = $engine->open($wave, $position);
            }
        } catch (\Throwable $exception) {
            self::abortEvery($calls, $exception->getMessage());

            throw $exception;
        }

        return $calls;
    }

    /**
     * A throw here means no call got a reply (an unreadable key, say): every opened row is settled first, so none
     * reads as "still running", then the error propagates unchanged.
     *
     * @template TWave of BatchWaveInterface
     * @template TRequest of object
     * @template TOutcome of BatchCallOutcomeInterface
     *
     * @param BatchWaveEngineInterface<TWave, TRequest, TOutcome> $engine
     * @param TWave                                               $wave
     * @param non-empty-list<BatchCall<TRequest>>                 $calls
     *
     * @return RateLimitedResultModel<TOutcome>
     */
    private static function sendAll(
        BatchWaveEngineInterface $engine,
        BatchWaveInterface $wave,
        array $calls,
    ): RateLimitedResultModel {
        try {
            return $engine->sendAll($wave, $calls);
        } catch (\Throwable $exception) {
            self::abortEvery($calls, $exception->getMessage());

            throw $exception;
        }
    }

    /** @param list<BatchCall<object>> $calls */
    private static function abortEvery(array $calls, string $detail): void
    {
        foreach ($calls as $call) {
            $call->recordedCall->abortAfterTransportFailure($detail);
        }
    }

    /**
     * The atomic-wave rule: one transport failure settles every call of the round and banks none of it. A healthy
     * sibling's answer is discarded and re-billed next tick; that cost is accepted, not a bug.
     *
     * @param list<BatchCall<object>>         $calls
     * @param list<BatchCallOutcomeInterface> $outcomes aligned to $calls
     */
    private static function guardWaveTransport(array $calls, array $outcomes): void
    {
        $waveFailure = self::firstFailureIn($outcomes);
        if (null === $waveFailure) {
            return;
        }

        foreach ($outcomes as $index => $outcome) {
            $calls[$index]->recordedCall->abortAfterTransportFailure(self::abortDetailFor($outcome, $waveFailure));
        }

        throw $waveFailure;
    }

    /** A call with its own cause, a spoiled reply included, names it; only a bystander borrows the wave's. */
    private static function abortDetailFor(BatchCallOutcomeInterface $outcome, \Throwable $waveFailure): string
    {
        return $outcome->hasCause() ? $outcome->cause()->getMessage() : $waveFailure->getMessage();
    }

    /**
     * A refusal wins over any other cause, since retrying it earns the same answer; otherwise the first by index.
     *
     * @param list<BatchCallOutcomeInterface> $outcomes
     */
    private static function firstFailureIn(array $outcomes): ?\Throwable
    {
        $first = null;
        foreach ($outcomes as $outcome) {
            if (!$outcome->isFailure()) {
                continue;
            }
            if ($outcome->cause() instanceof ProviderRejectedRequestException) {
                return $outcome->cause();
            }
            $first ??= $outcome->cause();
        }

        return $first;
    }

    /**
     * @template TRequest of object
     * @template TOutcome of BatchCallOutcomeInterface
     *
     * @param list<int>                 $pending  positions into the wave, in call order
     * @param list<BatchCall<TRequest>> $calls    one per call, aligned to $pending
     * @param list<TOutcome>            $outcomes one per call, aligned to $pending
     *
     * @return array<int, array{call: BatchCall<TRequest>, outcome: TOutcome}>
     */
    private static function repliesByPosition(array $pending, array $calls, array $outcomes): array
    {
        $replies = [];
        foreach ($pending as $index => $position) {
            $replies[$position] = ['call' => $calls[$index], 'outcome' => $outcomes[$index]];
        }

        return $replies;
    }
}
