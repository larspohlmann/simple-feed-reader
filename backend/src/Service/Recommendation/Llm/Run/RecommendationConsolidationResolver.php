<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Run;

use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\ConsolidationParseResultModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationPickModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Llm\Prompt\RecommendationConsolidationParser;
use App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Llm\Run\Model\CallSlotModel;
use App\Service\Recommendation\Llm\Run\Model\ConsolidationOutcomeModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Pool\RecommendationHistoryLoader;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use App\Service\Recommendation\Run\RecommendationWinnerRanker;

/**
 * The consolidation phase's one provider call: re-score, reason and dedupe the top of the pool in one pass. A pool
 * pruned to nothing finalizes free; an unusable reply comes back with the ranking to degrade to: what a reply the
 * provider cut short finished, else the batch-score pool.
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

    public function resolve(TickContext $tick): ConsolidationOutcomeModel
    {
        $run = $tick->run;
        $prompt = new PromptContext(
            $this->historyLoader->load($tick->userId(), $tick->settings),
            $tick->settings,
            $run->getProfileText(),
        );
        $inputSize = $this->promptBuilder->consolidationInputSize($prompt, Reasoning::preferredBy($tick->connection));
        $pool = $this->ranker->cutForConsolidation($this->ranker->ranked($run->getWinners()), $inputSize);
        $linesById = $this->candidateLoader->linesForIds($tick->userId(), array_column($pool, 'id'));
        $pool = self::stillPresent($pool, $linesById);

        if ([] === $pool) {
            return ConsolidationOutcomeModel::finalizeWith([]);
        }

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->consolidationMessages($prompt, $pool, $linesById),
            $run->getLastInvalidReply(),
            RecommendationPromptText::CONSOLIDATION_CORRECTIVE,
        );

        $request = $this->requestFactory->create(
            $tick->connection,
            new CallPromptModel($messages, \count($pool), RecommendationResponseSchema::Consolidation),
        );
        $recordedCall = $this->callRecorder->begin($run, CallSlotModel::consolidation(), $request);
        $content = $this->providerCall->complete($tick, $request, $recordedCall);

        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcomeModel::unusable(
                $content,
                $recordedCall->providerCutTheAnswer() ? $this->salvagedRankingOrPool($content, $pool) : $pool,
            );
        }

        $recordedCall->finishUsable($content);
        $this->checkpoint->guard($run);

        return ConsolidationOutcomeModel::finalizeWith(self::rankedFromReply($result));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $pool
     * @param array<int, ArticleLineModel>                      $linesById entries pruned since their batch are absent
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
     * @param list<array{id: int, score: int, reason: string}> $pool
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private function salvagedRankingOrPool(string $content, array $pool): array
    {
        $salvaged = $this->consolidationParser->parseCutReply($content, array_column($pool, 'id'));

        return $salvaged->usable ? self::rankedFromReply($salvaged) : $pool;
    }

    /**
     * Exactly the entries the reply scored, minus its named duplicates, best first: consolidation is the sole
     * authority on the final feed, so an entry it did not mention is dropped, not kept at its batch score.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function rankedFromReply(ConsolidationParseResultModel $result): array
    {
        $ranked = array_values(array_filter(
            self::picksById($result->picks),
            static fn (array $pick): bool => !\in_array($pick['id'], $result->duplicateIds, true),
        ));

        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $ranked;
    }

    /**
     * @param list<RecommendationPickModel> $picks
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
