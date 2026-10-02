<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Entity\RecommendationRun;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Model\RateLimitedResultModel;
use App\Service\Ai\RateLimitedCalls;
use App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneReplyModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Jev\Support\RenderedSystemOneRequest;
use App\Service\Recommendation\Jev\SystemOneClient\SystemOneClientInterface;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\Model\BatchWaveResultModel;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Run\Pass\RecordedCall;
use App\Service\Recommendation\Run\RecommendationCallRecorder;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use App\Service\Recommendation\Run\Support\BatchWaveWinners;

/**
 * One System One request per batch, every one a run-log row. An unusable reply retries its batch alone up to
 * MAX_ATTEMPTS rounds, then yields no winners. An endpoint failure settles every call and banks nothing, the
 * atomic-wave rule; a deferring plan's 429 throws ProviderRateLimitedException instead.
 */
final readonly class JevBatchWave
{
    public function __construct(
        private RateLimitedCalls $rateLimitedCalls,
        private SystemOneClientInterface $client,
        private AiProviderConfigurator $configurator,
        private RecommendationCallRecorder $callRecorder,
        private SystemOneRequestFactory $requestFactory,
        private NoulReplyParser $parser,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    public function resolve(JevWave $wave): BatchWaveResultModel
    {
        $rateLimitObserved = false;
        [$winners, $pending] = BatchWaveWinners::splitByPruned($wave->batches);

        for ($round = 1; [] !== $pending; $round++) {
            $roundResult = $this->sendRound($wave, $pending);
            $rateLimitObserved = $rateLimitObserved || $roundResult['observed'];
            $pending = [];
            foreach ($roundResult['replies'] as $position => $answered) {
                $parsed = $this->parser->parse($answered['reply'], self::idsOf($wave->batches[$position]));
                if ($parsed->usable) {
                    $answered['call']->finishUsable($answered['reply']->body);
                    $winners[$position] = $parsed->winners;

                    continue;
                }
                $answered['call']->finishUnusable($answered['reply']->body);
                $pending[] = $position;
            }

            $this->checkpoint->guard($wave->tick->run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return new BatchWaveResultModel(BatchWaveWinners::degradeUnresolved($winners, $pending), $rateLimitObserved);
    }

    /**
     * @param non-empty-list<int> $pending positions into the wave still awaiting a usable reply
     *
     * @return array{replies: array<int, array{reply: SystemOneReplyModel, call: RecordedCall}>, observed: bool}
     */
    private function sendRound(JevWave $wave, array $pending): array
    {
        $requests = array_map(
            fn (int $position): SystemOneRequestModel => $this->requestFactory->create(
                $wave->model(),
                $wave->state,
                $wave->batches[$position]->linesInSnapshotOrder(),
            ),
            $pending,
        );
        $recordedCalls = array_map(
            fn (int $position, SystemOneRequestModel $request): RecordedCall => $this->callRecorder->begin(
                $wave->tick->run,
                CallSlotModel::batch($wave->batches[$position]->index + 1),
                RenderedSystemOneRequest::of($request),
            ),
            $pending,
            $requests,
        );

        $result = $this->sendAll($wave, $requests, $recordedCalls);
        if ($result->isDeferred()) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure('Provider rate limited; deferring.');
            }

            throw new ProviderRateLimitedException($result->deferSeconds);
        }

        self::guardWaveTransport($recordedCalls, $result->outcomes);

        return [
            'replies' => self::repliesByPosition($pending, $result->outcomes, $recordedCalls),
            'observed' => $result->rateLimitObserved,
        ];
    }

    /**
     * A throw here means no call got a reply (an unreadable key, say): every opened row is settled first, so none
     * reads as "still running", then the error propagates unchanged.
     *
     * @param non-empty-list<SystemOneRequestModel> $requests
     * @param list<RecordedCall>                    $recordedCalls
     *
     * @return RateLimitedResultModel<SystemOneOutcomeModel>
     */
    private function sendAll(JevWave $wave, array $requests, array $recordedCalls): RateLimitedResultModel
    {
        try {
            $credentials = $this->configurator->credentials($wave->tick->connection);
            $callsByRequest = self::callsByRequest($requests, $recordedCalls);

            return $this->rateLimitedCalls->send(
                $requests,
                fn (array $subset): array => self::receiveAnswers(
                    $callsByRequest,
                    $subset,
                    $this->client->evaluateMany($credentials, $subset),
                ),
                $wave->tick->retryPlan(),
            );
        } catch (\Throwable $exception) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure($exception->getMessage());
            }

            throw $exception;
        }
    }

    /**
     * @param non-empty-list<SystemOneRequestModel> $requests
     * @param list<RecordedCall>                    $recordedCalls aligned to $requests
     *
     * @return \SplObjectStorage<SystemOneRequestModel, RecordedCall>
     */
    private static function callsByRequest(array $requests, array $recordedCalls): \SplObjectStorage
    {
        /** @var \SplObjectStorage<SystemOneRequestModel, RecordedCall> $callsByRequest */
        $callsByRequest = new \SplObjectStorage();
        foreach ($requests as $index => $request) {
            $callsByRequest[$request] = $recordedCalls[$index];
        }

        return $callsByRequest;
    }

    /**
     * Books each paid answer the moment it arrives, so a sibling's failure or a deferral still bills what it cost. Only
     * a limited request is re-sent, so no answer is booked twice.
     *
     * @param \SplObjectStorage<SystemOneRequestModel, RecordedCall> $callsByRequest
     * @param non-empty-list<SystemOneRequestModel>                  $subset
     * @param list<SystemOneOutcomeModel>                            $outcomes       aligned to $subset
     *
     * @return list<SystemOneOutcomeModel>
     */
    private static function receiveAnswers(\SplObjectStorage $callsByRequest, array $subset, array $outcomes): array
    {
        foreach ($outcomes as $index => $outcome) {
            if ($outcome->isFailure()) {
                continue;
            }
            $reply = $outcome->reply();
            $callsByRequest[$subset[$index]]->received($reply->receipt, \strlen($reply->body));
        }

        return $outcomes;
    }

    /**
     * The atomic-wave rule: one endpoint failure settles every call of the round and banks none of it.
     *
     * @param list<RecordedCall>          $recordedCalls
     * @param list<SystemOneOutcomeModel> $outcomes
     */
    private static function guardWaveTransport(array $recordedCalls, array $outcomes): void
    {
        $failed = array_values(array_filter(
            $outcomes,
            static fn (SystemOneOutcomeModel $outcome): bool => $outcome->isFailure(),
        ));
        if ([] === $failed) {
            return;
        }

        $waveFailure = $failed[0]->cause();
        foreach ($outcomes as $position => $outcome) {
            $cause = $outcome->isFailure() ? $outcome->cause() : $waveFailure;
            $recordedCalls[$position]->abortAfterTransportFailure($cause->getMessage());
        }

        throw $waveFailure;
    }

    /**
     * @param list<int>                   $pending       positions into the wave, in call order
     * @param list<SystemOneOutcomeModel> $outcomes      one per call, aligned to $pending
     * @param list<RecordedCall>          $recordedCalls one per call, aligned to $pending
     *
     * @return array<int, array{reply: SystemOneReplyModel, call: RecordedCall}>
     */
    private static function repliesByPosition(array $pending, array $outcomes, array $recordedCalls): array
    {
        $replies = [];
        foreach ($pending as $callIndex => $position) {
            $replies[$position] = ['reply' => $outcomes[$callIndex]->reply(), 'call' => $recordedCalls[$callIndex]];
        }

        return $replies;
    }

    /** @return list<int> */
    private static function idsOf(WaveBatchModel $batch): array
    {
        return array_map(static fn (ArticleLineModel $line): int => $line->entryId, $batch->linesInSnapshotOrder());
    }
}
