<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Prompt\ConsolidationParseResult;
use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Prompt\RecommendationCandidateLoader;
use App\Service\Recommendation\Prompt\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Prompt\RecommendationConsolidationParser;
use App\Service\Recommendation\Prompt\RecommendationHistoryLoader;
use App\Service\Recommendation\Prompt\RecommendationPick;
use App\Service\Recommendation\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Prompt\RecommendationPromptText;
use App\Service\Recommendation\Prompt\RecommendationResponseSchema;

/**
 * The consolidation phase's one provider call (#493): re-score, reason and dedupe the top of the pool in one pass.
 * A pool pruned to nothing finalizes free; an unusable reply comes back with the batch-score pool to degrade to.
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

    public function resolve(TickContext $tick): ConsolidationOutcome
    {
        $run = $tick->run;
        $settings = $tick->settings;
        $history = $this->historyLoader->load($tick->userId(), $settings);
        $inputSize = $this->promptBuilder->consolidationInputSize(
            $settings->packing->contextWindow,
            $history,
            $run->getProfileText(),
            $settings->picksLimit,
            $tick->connection->suppressesReasoning(),
        );
        $pool = $this->ranker->cutForConsolidation($this->ranker->ranked($run->getWinners()), $inputSize);
        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            return ConsolidationOutcome::finalizeWith([]);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->consolidationMessages(
                $pool,
                $linesById,
                $history,
                $settings,
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
            $tick->model(),
        );

        $content = $this->providerCall->complete(
            $tick,
            $this->requestFactory->create(
                $tick->connection,
                $messages,
                \count($pool),
                RecommendationResponseSchema::Consolidation,
            ),
            $recordedCall,
        );

        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcome::unusable($content, $pool);
        }

        $recordedCall->finishUsable($content);
        $this->checkpoint->guard($run);

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
     * Exactly the entries the reply scored, minus its named duplicates, best first: consolidation is the sole
     * authority on the final feed, so an entry it did not mention is dropped, not kept at its batch score.
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
