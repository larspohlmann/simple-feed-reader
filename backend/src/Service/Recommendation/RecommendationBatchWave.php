<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * The batch phase's concurrent fan-out (#344): one tick resolves a wave of
 * batches instead of a single call. Every not-yet-pruned batch of the wave is
 * fired at once through completeMany; a batch whose reply is unusable is
 * retried -- only it, and only with its own corrective tail built from its own
 * last invalid reply, held in a local map -- for up to MAX_ATTEMPTS rounds,
 * then degraded to an empty winner set (#329). A fully-pruned batch resolves
 * free, as an empty winner set.
 *
 * A transport failure anywhere in a round is the atomic-wave rule: settle
 * every in-flight call and re-throw the first failure, banking nothing. The
 * caller (RecommendationRunAdvancer) turns that throw into one ceiling
 * increment for the whole wave and re-runs the wave next tick, so this class
 * never touches the run's persisted progress -- it only reads the plan, reads
 * the provider, and settles the debug rows it opened.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class RecommendationBatchWave
{
    public function __construct(
        private ChatCompletionClient $chat,
        private ProviderConnectionFactory $connections,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationPickParser $parser,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationCancellationCheckpoint $cancellation,
    ) {
    }

    /**
     * Resolves the next $waveSize batches of the frozen plan and returns each
     * batch's winners in plan order, so the caller banks them and advances the
     * cursor by the wave size. Returns only once every batch is banked or
     * degraded; a transport failure re-throws instead (recorded calls settled).
     *
     * @return list<list<array{id: int, score: int, reason: string}>> winners per batch, in plan order
     *
     * @throws \App\Service\Ai\Exception\ProviderUnreachableException
     * @throws \App\Service\Ai\Exception\CredentialsRejectedException
     */
    public function resolve(
        RecommendationRun $run,
        AiProviderSettings $settings,
        EffectiveRecommendationSettings $effectiveSettings,
        int $userId,
        int $waveSize,
    ): array {
        $waveBatches = $this->waveBatches($run, $userId, $waveSize);
        $poolSummary = $this->candidateLoader->summarize($userId, $this->allCandidateIds($run));
        $history = $this->historyLoader->load($userId, $effectiveSettings);
        $correctiveReply = [];
        [$winners, $pending] = $this->splitByPruned($waveBatches);

        for ($round = 1; [] !== $pending; $round++) {
            $replies = $this->sendRound(
                $run,
                $settings,
                $effectiveSettings,
                $history,
                $waveBatches,
                $pending,
                $correctiveReply,
                $poolSummary,
            );
            $pending = [];
            foreach ($replies as $position => $reply) {
                $result = $this->parser->parse($reply['content'], $waveBatches[$position]->validIds());
                $reply['call']->settle($reply['content'], $result->usable);
                if ($result->usable) {
                    $winners[$position] = self::asWinners($result->picks);

                    continue;
                }
                $correctiveReply[$position] = $reply['content'];
                $pending[] = $position;
            }

            $this->cancellation->guard($run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return $this->degradeUnresolved($winners, $pending);
    }

    /**
     * Resolves the next $waveSize batches of the plan to their entry ids and
     * the prompt lines those ids still resolve to. Every batch's ids are
     * resolved through one linesForIds() call over their union, not one call
     * per batch, then split back out per batch by key -- the wave sends up
     * to MAX_BATCH_CONCURRENCY batches at once, and this is the difference
     * between one round trip and one per batch.
     *
     * @return list<WaveBatch>
     */
    private function waveBatches(RecommendationRun $run, int $userId, int $waveSize): array
    {
        $startIndex = $run->progress()->nextBatchIndex;
        $candidateBatches = $run->getCandidateBatches();

        $idsByPosition = [];
        for ($index = $startIndex; $index < $startIndex + $waveSize; $index++) {
            $idsByPosition[$index] = $candidateBatches[$index];
        }

        $linesById = $this->candidateLoader->linesForIds($userId, array_merge(...array_values($idsByPosition)));

        $waveBatches = [];
        foreach ($idsByPosition as $index => $ids) {
            $waveBatches[] = new WaveBatch($index, $ids, array_intersect_key($linesById, array_flip($ids)));
        }

        return $waveBatches;
    }

    /**
     * Every candidate id across every batch of the frozen plan, flattened. This
     * is the whole snapshot pool, so the pool summary derived from it is the
     * same global frame for every batch of the run, not the batch's own dates.
     *
     * @return list<int>
     */
    private function allCandidateIds(RecommendationRun $run): array
    {
        $candidateBatches = $run->getCandidateBatches();

        return array_merge(...$candidateBatches);
    }

    /**
     * Splits the wave's batches into the ones already resolved and the ones
     * still owing a provider call. A fully-pruned batch is resolved for
     * free -- it seeds $winners with an empty set, the per-batch form of the
     * all-pruned short-circuit -- everything else is a pending position.
     *
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
     * Drops every batch still unusable at the last round to an empty winner
     * set, then returns the wave's winners in plan order.
     *
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
     * Fires one round of the wave: a fresh RecordedCall and request per still-
     * pending batch -- each carrying that batch's own corrective tail -- read
     * concurrently through completeMany. A transport failure anywhere in the
     * round is the atomic-wave rule (see guardWaveTransport): the round settles
     * every call and throws. On success it hands each reply back keyed by its
     * batch position for the caller to parse and settle.
     *
     * @param list<WaveBatch>     $waveBatches
     * @param non-empty-list<int> $pending         positions into $waveBatches still awaiting a usable reply
     * @param array<int, string>  $correctiveReply each position's own last invalid reply
     *
     * @return array<int, array{content: string, call: RecordedCall}> keyed by batch position
     */
    private function sendRound(
        RecommendationRun $run,
        AiProviderSettings $settings,
        EffectiveRecommendationSettings $effectiveSettings,
        RecommendationHistory $history,
        array $waveBatches,
        array $pending,
        array $correctiveReply,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $calls = [];
        $recordedCalls = [];
        foreach ($pending as $position) {
            $waveBatch = $waveBatches[$position];
            $messages = $this->batchMessages(
                $history,
                $waveBatch,
                $effectiveSettings,
                $correctiveReply[$position] ?? null,
                $poolSummary,
            );
            $recordedCall = $this->callRecorder->begin(
                $run,
                RecommendationRunLog::PHASE_BATCH,
                $waveBatch->index + 1,
                $messages,
                $settings->getModel() ?? '',
            );
            $calls[] = new ConcurrentCompletion(
                $this->requestFactory->create(
                    $settings,
                    $messages,
                    \count($waveBatch->validIds()),
                    RecommendationResponseSchema::Ranking,
                ),
                $recordedCall,
            );
            $recordedCalls[] = $recordedCall;
        }

        $outcomes = $this->completeRound($settings, $calls, $recordedCalls);
        $this->guardWaveTransport($recordedCalls, $outcomes);

        return $this->repliesByPosition($pending, $outcomes, $recordedCalls);
    }

    /**
     * Reads the whole round concurrently. completeMany never throws for a
     * per-call transport failure -- it folds each into that call's outcome --
     * so the only throw here produced no reply at all for any call (an
     * unreadable key, say, raised while resolving credentials). That settles
     * every opened row so none is left reading as "still streaming", then
     * re-throws unchanged (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     * @param list<RecordedCall>                   $recordedCalls
     *
     * @return list<CompletionOutcome>
     */
    private function completeRound(AiProviderSettings $settings, array $calls, array $recordedCalls): array
    {
        try {
            return $this->chat->completeMany($this->connections->forSettings($settings), $calls);
        } catch (\Throwable $e) {
            foreach ($recordedCalls as $recordedCall) {
                $recordedCall->abortAfterTransportFailure($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * The atomic-wave rule (#344): if any call in the round hit a transport
     * failure, settle every call this round opened a log row for and re-throw
     * the first failure, banking nothing. completeMany cancels only the
     * failed call's response -- a healthy sibling keeps streaming to
     * completion on its own connection and its answer is simply discarded,
     * since the wave never banks a partial round. The caller records one
     * ceiling increment for the whole wave and re-runs it next tick; the
     * discarded siblings' provider spend is the accepted re-bill cost of that
     * re-run, not a bug to fix by cancelling them too.
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
            $detail = $outcome->isFailure() ? $outcome->cause()->getMessage() : $firstFailure->getMessage();
            $recordedCalls[$position]->abortAfterTransportFailure($detail);
        }

        throw $firstFailure;
    }

    /**
     * @param list<CompletionOutcome> $outcomes
     */
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
     * Re-keys the round's outcomes from call order back to batch position, so
     * the caller parses each reply against the right batch's ids.
     *
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
            $replies[$position] = ['content' => $outcomes[$callIndex]->content(), 'call' => $recordedCalls[$callIndex]];
        }

        return $replies;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function batchMessages(
        RecommendationHistory $history,
        WaveBatch $waveBatch,
        EffectiveRecommendationSettings $effectiveSettings,
        ?string $lastInvalidReply,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $candidateLines = $this->linesInSnapshotOrder($waveBatch->ids, $waveBatch->linesById);
        $messages = $this->promptBuilder->batchMessages(
            $history,
            $candidateLines,
            $effectiveSettings,
            $poolSummary,
        );

        return $this->promptBuilder->messagesWithCorrectiveTail(
            $messages,
            $lastInvalidReply,
            RecommendationPromptText::CORRECTIVE,
        );
    }

    /**
     * @param list<int>              $ids       the batch's entry ids, in snapshot order
     * @param array<int, PromptLine> $linesById entries pruned since the snapshot are simply absent
     *
     * @return list<PromptLine>
     */
    private function linesInSnapshotOrder(array $ids, array $linesById): array
    {
        $present = array_filter($ids, static fn (int $id): bool => isset($linesById[$id]));

        return array_values(array_map(static fn (int $id): PromptLine => $linesById[$id], $present));
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
