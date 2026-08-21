<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * The dedup phase's single provider call (#338 lifted it out of
 * RecommendationRunAdvancer). Once every batch has ranked its own candidates, a
 * multi-batch run sends the score-ordered cut of the pool to the model and asks
 * which entries duplicate a better-ranked story. This class mirrors
 * RecommendationBatchWave: it reads the plan, calls the provider, parses the
 * reply and settles the debug row it opened, then hands back a DedupOutcome for
 * the advancer to write. It never touches the run's persisted progress and
 * never finalizes.
 *
 * A pool emptied by mid-run pruning has nothing to check, so it resolves free to
 * an empty finalize list with no provider call -- mirroring the batch phase's
 * all-pruned short-circuit. A usable reply resolves to the pool minus the named
 * duplicates; an unusable one resolves to the offending reply and the undeduped
 * pool, so the advancer can retry the call next tick or degrade to the undeduped
 * list once attempts run out. A transport failure throws, exactly as the batch
 * wave does, and the advancer folds it into the run's ceiling.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class RecommendationDedupResolver
{
    public function __construct(
        private RecommendationWinnerRanker $ranker,
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private ChatCompletionClient $chat,
        private ProviderConnectionFactory $connections,
        private RecommendationDuplicateParser $duplicateParser,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    public function resolve(
        RecommendationRun $run,
        AiProviderSettings $settings,
        int $userId,
        int $picksLimit,
    ): DedupOutcome {
        $pool = $this->ranker->cutForDedup($this->ranker->ranked($run->getWinners()), $picksLimit);
        $linesById = $this->candidateLoader->linesForIds($userId, array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            // Every ranked entry was pruned since its batch ran: there is
            // nothing left to dedup, so this is progress, not failure --
            // mirrors the batch phase's own all-pruned short-circuit.
            return DedupOutcome::finalizeWith([]);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->dedupMessages($pool, $linesById),
            $run->getLastInvalidReply(),
            RecommendationPromptText::DEDUP_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DEDUP,
            null,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->callProvider(
            $settings,
            $this->requestFactory->create(
                $settings,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Duplicates,
            ),
            $recordedCall,
        );

        $result = $this->duplicateParser->parse($content, array_column($pool, 'id'));
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return DedupOutcome::unusable($content, $pool);
        }

        return DedupOutcome::finalizeWith(self::withoutDuplicates($pool, $result->duplicateIds));
    }

    /**
     * The dedup phase's single provider call, recorded for the debug view from
     * the moment the request goes out (#309). Any failure settles the debug row
     * before unwinding: begin() has already persisted it, and a verdict left
     * null reads to the debug panel as "still streaming" forever (its one other
     * producer, streamingTextForUser(), cannot tell a genuinely abandoned call
     * from a live one). The exception is always re-thrown unchanged, so the
     * advancer still tells a transport failure (which touches the run's ceiling)
     * apart from an unreadable key (which fails the run permanently) purely by
     * its type -- credentials() decrypting the stored key runs inside this same
     * try, so an unreadable key never leaves the row stuck either.
     */
    private function callProvider(
        AiProviderSettings $settings,
        CompletionRequest $request,
        RecordedCall $recordedCall,
    ): string {
        try {
            return $this->chat->complete(
                $this->connections->forSettings($settings),
                $request,
                $recordedCall,
            );
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $pool
     * @param array<int, PromptLine>                           $linesById entries pruned since their batch are absent
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function stillPresent(array $pool, array $linesById): array
    {
        return array_values(array_filter(
            $pool,
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));
    }

    /**
     * Never reaches below the dedup cut for backfill: entries beyond it were
     * never shown to the dedup call, so pulling them in could reintroduce
     * unchecked duplicates. A final list shorter than the picks limit is the
     * accepted cost.
     *
     * How much shorter is bounded at the parser, not here: a reply naming more
     * than half of the entries it was shown never reaches this method, so at
     * least half the pool always survives and a completed run always has
     * recommendations in it (#396).
     *
     * @param non-empty-list<array{id: int, score: int, reason: string}> $pool
     * @param list<int>                                                  $duplicateIds
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function withoutDuplicates(array $pool, array $duplicateIds): array
    {
        return array_values(array_filter(
            $pool,
            static fn (array $winner): bool => !\in_array($winner['id'], $duplicateIds, true),
        ));
    }
}
