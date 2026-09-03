<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;

/**
 * The consolidation phase's single provider call (#493), replacing the earlier two-call
 * rank-then-dedup pipeline: one call over the top-2x-picksLimit pool re-scores every entry
 * against the reader's profile and history, gives each a reason, and flags duplicates in
 * one pass. Like RecommendationProfileDistiller, it loads what it needs, calls the
 * provider, parses the reply, settles the debug row it opened, and hands back a
 * ConsolidationOutcome for the advancer to write; it never touches persisted progress or
 * finalizes on its own.
 *
 * A pool emptied by mid-run pruning resolves free to an empty finalize list, no call --
 * mirrors RecommendationBatchWave's all-pruned short-circuit. A usable reply resolves to
 * the pool's picks re-scored and re-reasoned, minus the named duplicates, best first; an
 * unusable one resolves to the offending reply plus the pool at its batch scores with empty
 * reasons, so the advancer can retry or degrade once attempts run out. A transport failure
 * throws, as in the distillation resolver, and the advancer folds it into the run's ceiling.
 */
final readonly class RecommendationConsolidationResolver
{
    public function __construct(
        private RecommendationWinnerRanker $ranker,
        private RecommendationCandidateLoader $candidateLoader,
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationProviderCall $providerCall,
        private RecommendationConsolidationParser $consolidationParser,
        private RecommendationTickCheckpoint $checkpoint,
    ) {
    }

    public function resolve(
        RecommendationRun $run,
        AiProviderSettings $settings,
        int $userId,
        int $picksLimit,
        EffectiveRecommendationSettings $effectiveSettings,
    ): ConsolidationOutcome {
        $history = $this->historyLoader->load($userId, $effectiveSettings);
        $inputSize = $this->promptBuilder->consolidationInputSize(
            $effectiveSettings->packing->contextWindow,
            $history,
            $run->getProfileText(),
            $picksLimit,
            $settings->suppressesReasoning(),
        );
        $pool = $this->ranker->cutForConsolidation($this->ranker->ranked($run->getWinners()), $inputSize);
        $linesById = $this->candidateLoader->linesForIds($userId, array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            // Every ranked entry was pruned since its batch ran: there is
            // nothing left to consolidate, so this is progress, not failure --
            // mirrors RecommendationBatchWave's own all-pruned short-circuit.
            return ConsolidationOutcome::finalizeWith([]);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->consolidationMessages(
                $pool,
                $linesById,
                $history,
                $effectiveSettings,
                $run->getProfileText(),
            ),
            $run->getLastInvalidReply(),
            RecommendationPromptText::CONSOLIDATION_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_CONSOLIDATE,
            null,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->providerCall->complete(
            $settings,
            $this->requestFactory->create(
                $settings,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Consolidation,
            ),
            $recordedCall,
        );

        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ConsolidationOutcome::unusable($content, $pool);
        }

        return ConsolidationOutcome::finalizeWith(self::rankedFromReply($result));
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
     * The final list: exactly the entries the reply scored and reasoned, minus
     * the ones it named as duplicates, best score first. An unmentioned pool
     * entry is dropped, not carried at its batch score -- the consolidation call
     * is the sole authority on the final feed, so every recommendation has a
     * real score and reason. A wholly unusable reply is handled upstream by the
     * degrade path, which keeps the batch pool; this shapes only a usable one.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function rankedFromReply(ConsolidationParseResult $result): array
    {
        $ranked = array_values(array_filter(
            self::picksById($result->picks),
            static fn (array $pick): bool => !\in_array($pick['id'], $result->duplicateIds, true),
        ));

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $ranked;
    }

    /**
     * @param list<RecommendationPick> $picks
     *
     * @return array<int, array{id: int, score: int, reason: string}>
     */
    private static function picksById(array $picks): array
    {
        $picksById = [];
        foreach ($picks as $pick) {
            $picksById[$pick->entryId] = ['id' => $pick->entryId, 'score' => $pick->score, 'reason' => $pick->reason];
        }

        return $picksById;
    }
}
