<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\Completion\CompletionOutcome;
use App\Service\Ai\Completion\ConcurrentCompletion;
use App\Service\Ai\Completion\RateLimitedCompletion;
use App\Service\Ai\Completion\RateLimitedResult;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\ProviderConnectionFactory;
use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationPick;
use App\Service\Recommendation\Prompt\RecommendationPickParser;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;

/**
 * The batch phase's concurrent fan-out (#344): an unusable batch retries alone up to MAX_ATTEMPTS rounds, then
 * yields no winners. A transport failure settles every open call and banks nothing, the atomic-wave rule; a
 * deferring plan's 429 throws ProviderRateLimitedException instead (#947).
 */
final readonly class RecommendationBatchWave
{
    public function __construct(
        private RateLimitedCompletion $completion,
        private ProviderConnectionFactory $connections,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationPickParser $parser,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    /**
     * @throws \App\Service\Ai\Exception\ProviderUnreachableException
     * @throws \App\Service\Ai\Exception\CredentialsRejectedException
     * @throws \App\Service\Ai\Exception\RetryableProviderException
     * @throws ProviderRateLimitedException
     */
    public function resolve(WaveContext $wave): BatchWaveResult
    {
        $correctiveReply = [];
        $rateLimitObserved = false;
        [$winners, $pending] = $this->splitByPruned($wave->batches);

        for ($round = 1; [] !== $pending; $round++) {
            $roundResult = $this->sendRound($wave, $pending, $correctiveReply);
            $rateLimitObserved = $rateLimitObserved || $roundResult['observed'];
            $pending = [];
            foreach ($roundResult['replies'] as $position => $reply) {
                $result = $this->parser->parse($reply['content'], $wave->batches[$position]->validIds());
                if ($result->usable) {
                    $reply['call']->finishUsable($reply['content']);
                    $winners[$position] = self::asWinners($result->picks);

                    continue;
                }
                $reply['call']->finishUnusable($reply['content']);
                $correctiveReply[$position] = $reply['content'];
                $pending[] = $position;
            }

            $this->checkpoint->guard($wave->tick->run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return new BatchWaveResult($this->degradeUnresolved($winners, $pending), $rateLimitObserved);
    }

    /**
     * @param list<WaveBatch> $waveBatches
     *
     * @return array{0: array<int, list<array{id: int, score: int, reason: string}>>, 1: list<int>}
     */
    private function splitByPruned(array $waveBatches): array
    {
        $winners = [];
        $pending = [];
        foreach ($waveBatches as $position => $waveBatch) {
            if ($waveBatch->isFullyPruned()) {
                $winners[$position] = [];

                continue;
            }
            $pending[] = $position;
        }

        return [$winners, $pending];
    }

    /**
     * @param array<int, list<array{id: int, score: int, reason: string}>> $winners
     * @param list<int>                                                    $stillUnresolved
     *
     * @return list<list<array{id: int, score: int, reason: string}>>
     */
    private function degradeUnresolved(array $winners, array $stillUnresolved): array
    {
        foreach ($stillUnresolved as $position) {
            $winners[$position] = [];
        }
        ksort($winners);

        return array_values($winners);
    }

    /**
     * @param non-empty-list<int> $pending         positions into the wave still awaiting a usable reply
     * @param array<int, string>  $correctiveReply each position's own last invalid reply
     *
     * @return array{replies: array<int, array{content: string, call: RecordedCall}>, observed: bool}
     */
    private function sendRound(WaveContext $wave, array $pending, array $correctiveReply): array
    {
        $tick = $wave->tick;
        $calls = [];
        $recordedCalls = [];
        foreach ($pending as $position) {
            $waveBatch = $wave->batches[$position];
            $messages = $this->batchMessages($wave, $waveBatch, $correctiveReply[$position] ?? null);
            $recordedCall = $this->callRecorder->begin(
                $tick->run,
                RecommendationRunLog::PHASE_BATCH,
                $waveBatch->index + 1,
                $messages,
                $tick->model(),
            );
            $calls[] = new ConcurrentCompletion(
                $this->requestFactory->create(
                    $tick->connection,
                    $messages,
                    \count($waveBatch->validIds()),
                    RecommendationResponseSchema::BatchScore,
                ),
                $recordedCall,
            );
            $recordedCalls[] = $recordedCall;
        }

        $result = $this->completeRound($tick, $calls, $recordedCalls);

        if ($result->isDeferred()) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure('Provider rate limited; deferring.');
            }

            throw new ProviderRateLimitedException($result->deferSeconds);
        }

        $outcomes = $result->outcomes;
        $this->guardWaveTransport($recordedCalls, $outcomes);

        return [
            'replies' => $this->repliesByPosition($pending, $outcomes, $recordedCalls),
            'observed' => $result->rateLimitObserved,
        ];
    }

    /**
     * A throw here means no call got a reply (an unreadable key, say): every opened row is settled first, so none
     * reads as "still streaming", then the error propagates unchanged (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<RecordedCall>                   $recordedCalls
     */
    private function completeRound(TickContext $tick, array $calls, array $recordedCalls): RateLimitedResult
    {
        try {
            return $this->completion->completeMany(
                $this->connections->forSettings($tick->connection),
                $calls,
                $tick->retryPlan(),
            );
        } catch (\Throwable $e) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * The atomic-wave rule (#344): one transport failure settles every call of the round and banks none of it. A
     * healthy sibling's answer is discarded and re-billed next tick; that cost is accepted, not a bug.
     *
     * @param list<RecordedCall>      $recordedCalls
     * @param list<CompletionOutcome> $outcomes
     */
    private function guardWaveTransport(array $recordedCalls, array $outcomes): void
    {
        $firstFailure = $this->firstFailureIn($outcomes);
        if (null === $firstFailure) {
            return;
        }

        foreach ($outcomes as $position => $outcome) {
            $recordedCalls[$position]->abortAfterTransportFailure(self::abortDetailFor($outcome, $firstFailure));
        }

        throw $firstFailure;
    }

    /** A call with its own cause, a spoiled reply included, names it; only a bystander borrows the wave's (#437). */
    private static function abortDetailFor(CompletionOutcome $outcome, \Throwable $waveFailure): string
    {
        return $outcome->hasCause() ? $outcome->cause()->getMessage() : $waveFailure->getMessage();
    }

    /** @param list<CompletionOutcome> $outcomes */
    private function firstFailureIn(array $outcomes): ?\Throwable
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->isFailure()) {
                return $outcome->cause();
            }
        }

        return null;
    }

    /**
     * @param list<int>               $pending       positions into the wave, in call order
     * @param list<CompletionOutcome> $outcomes      one per call, aligned to $pending
     * @param list<RecordedCall>      $recordedCalls one per call, aligned to $pending
     *
     * @return array<int, array{content: string, call: RecordedCall}>
     */
    private function repliesByPosition(array $pending, array $outcomes, array $recordedCalls): array
    {
        $replies = [];
        foreach ($pending as $callIndex => $position) {
            // content() covers a spoiled reply too: the partial answer the parser judges and the retry quotes back.
            $replies[$position] = [
                'content' => $outcomes[$callIndex]->content(),
                'call' => $recordedCalls[$callIndex],
            ];
        }

        return $replies;
    }

    /** @return list<array{role: string, content: string}> */
    private function batchMessages(WaveContext $wave, WaveBatch $waveBatch, ?string $lastInvalidReply): array
    {
        $messages = $this->promptBuilder->batchMessages(
            $wave->history,
            $this->linesInSnapshotOrder($waveBatch),
            $wave->tick->settings,
            $wave->profile,
            $wave->poolSummary,
        );

        return $this->promptBuilder->messagesWithCorrectiveTail(
            $messages,
            $lastInvalidReply,
            RecommendationPromptText::CORRECTIVE,
        );
    }

    /** @return list<PromptLine> */
    private function linesInSnapshotOrder(WaveBatch $waveBatch): array
    {
        $present = array_filter($waveBatch->ids, static fn (int $id): bool => isset($waveBatch->linesById[$id]));

        return array_values(array_map(static fn (int $id): PromptLine => $waveBatch->linesById[$id], $present));
    }

    /**
     * @param list<RecommendationPick> $picks
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function asWinners(array $picks): array
    {
        return array_map(
            static fn (RecommendationPick $pick): array => [
                'id' => $pick->entryId,
                'score' => $pick->score,
                'reason' => $pick->reason,
            ],
            $picks,
        );
    }
}
