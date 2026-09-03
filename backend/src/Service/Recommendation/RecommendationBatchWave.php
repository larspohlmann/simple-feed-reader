<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * The batch phase's concurrent fan-out (#344): one tick resolves a wave of
 * batches through completeMany. An unusable batch is retried alone -- with its
 * own corrective tail from its own last invalid reply -- for up to MAX_ATTEMPTS
 * rounds, then degraded to an empty winner set (#329); a fully-pruned batch
 * resolves free to one.
 *
 * A transport failure in a round is the atomic-wave rule: settle every in-flight
 * call, re-throw the first failure, bank nothing. The caller
 * (RecommendationRunAdvancer) turns that into one ceiling increment and re-runs
 * next tick, so this class never touches persisted progress -- it only reads the
 * plan and provider and settles the debug rows it opened.
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
        private RecommendationTickCheckpoint $checkpoint,
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
        $profile = $run->getProfileText();
        $correctiveReply = [];
        [$winners, $pending] = $this->splitByPruned($waveBatches);

        for ($round = 1; [] !== $pending; $round++) {
            $replies = $this->sendRound(
                $run,
                $settings,
                $effectiveSettings,
                $history,
                $profile,
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

            $this->checkpoint->guard($run);
            if ([] === $pending || $round >= RecommendationRun::MAX_ATTEMPTS) {
                break;
            }
        }

        return $this->degradeUnresolved($winners, $pending);
    }

    /**
     * Resolves the next $waveSize batches to their entry ids and the prompt
     * lines those ids still resolve to. All batches' ids go through one
     * linesForIds() call over their union, then split back by key -- with up to
     * MAX_BATCH_CONCURRENCY batches per wave, that is one round trip, not one
     * per batch.
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
     * Fires one round: a fresh RecordedCall and request per still-pending batch
     * -- each with its own corrective tail -- read concurrently through
     * completeMany. A transport failure is the atomic-wave rule (see
     * guardWaveTransport): settle every call and throw. On success it hands each
     * reply back keyed by batch position for the caller to parse and settle.
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
        ?string $profile,
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
                $profile,
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
                    RecommendationResponseSchema::BatchScore,
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
     * Reads the whole round concurrently. completeMany folds a per-call
     * transport failure into that call's outcome, so a throw here means no reply
     * for any call (an unreadable key raised while resolving credentials, say).
     * That settles every opened row so none is left reading as "still
     * streaming", then re-throws unchanged (#344).
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
     * The atomic-wave rule (#344): if any call hit a transport failure, settle
     * every call this round opened a log row for, re-throw the first failure,
     * bank nothing. completeMany cancels only the failed call's response; a
     * healthy sibling streams to completion and its answer is discarded, since
     * the wave never banks a partial round. The caller records one ceiling
     * increment and re-runs next tick; the discarded siblings' re-bill is the
     * accepted cost of that re-run, not a bug to fix by cancelling them too.
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

    /**
     * Why this call's row says it was aborted.
     *
     * Its own cause when it has one — including a spoiled reply, which is not an
     * endpoint failure but happened to this call. Only a call that lost its round
     * to a sibling borrows the wave's failure. Reading `isFailure()` here instead
     * stamped a runaway with a sibling's "That address did not answer", pointing
     * diagnosis at the network for a model failure — the misreport #437 removed.
     */
    private static function abortDetailFor(CompletionOutcome $outcome, \Throwable $waveFailure): string
    {
        return $outcome->hasCause() ? $outcome->cause()->getMessage() : $waveFailure->getMessage();
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
            // content() covers a spoiled reply too: it carries the partial
            // answer, which is exactly what the parser judges and the
            // corrective tail quotes back. guardWaveTransport has already
            // thrown for anything the endpoint failed to deliver at all.
            $replies[$position] = [
                'content' => $outcomes[$callIndex]->content(),
                'call' => $recordedCalls[$callIndex],
            ];
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
        ?string $profile,
        ?string $lastInvalidReply,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $candidateLines = $this->linesInSnapshotOrder($waveBatch->ids, $waveBatch->linesById);
        $messages = $this->promptBuilder->batchMessages(
            $history,
            $candidateLines,
            $effectiveSettings,
            $profile,
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
