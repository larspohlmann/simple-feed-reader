<?php

declare(strict_types=1);

namespace App\Service\Ai\Llm\Prompt;

use App\Service\Ai\Llm\Completion\Model\Reasoning;
use App\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchema;

/**
 * What the provider may spend answering, per phase. RecommendationPromptBuilder::packBatches() reserves this same
 * bound for a batch reply, so the packer and the provider cannot disagree.
 */
final readonly class RecommendationAnswerBudget
{
    /**
     * One scored consolidation pick with its prose `reason`, ~70 tokens measured. consolidationInputSize() subtracts
     * it, so too high shrinks the shortlist and too low crowds out the answer.
     */
    private const int TOKENS_PER_PICK = 70;

    /** A score-only batch pick, `{"id":123,"score":843}`: about a fifth of a reasoned one. */
    private const int TOKENS_PER_SCORE_PICK = 15;

    /**
     * The distillation reply: one `{"profile": "..."}` string of at most ~300 words, sized generously so a reasoning
     * model still finishes the JSON.
     */
    private const int PROFILE_ANSWER_TOKENS = 1200;

    /**
     * Half again over the mean estimate: a long reply is not a runaway to truncate into one that cannot parse, and
     * the bound stays an order of magnitude below the 33800 tokens that let a looping model run an hour.
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    private const int MINIMUM_ANSWER_TOKENS = 1024;

    /**
     * Reasoning is billed against the same `max_tokens` as the answer, so this rides on top of the answer reserve: a
     * 45-item batch capped at 1800 tokens spent it thinking and truncated its answer (#327). Finite: a runaway stops.
     */
    private const int REASONING_HEADROOM_TOKENS = 32000;

    /**
     * Suppression does not stop a local model thinking (qwen3.7-flash spent ~1900 tokens), so zero headroom cut the
     * answer at finish_reason: length, and the full 32000 made suppression meaningless; a quarter bounds the spend.
     */
    private const int SUPPRESSED_REASONING_HEADROOM_TOKENS = 8000;

    /**
     * Expected reply size plus slack, priced per phase: a batch entry is an id-score pair, a consolidation pick carries
     * prose, and distillation answers one profile string whatever the item count.
     */
    public function answerBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int
    {
        $expected = match ($schema) {
            RecommendationResponseSchema::Distillation => self::PROFILE_ANSWER_TOKENS,
            RecommendationResponseSchema::BatchScore => $replyItemCount * self::TOKENS_PER_SCORE_PICK,
            RecommendationResponseSchema::Consolidation => $replyItemCount * self::TOKENS_PER_PICK,
        };

        $bounded = max(self::MINIMUM_ANSWER_TOKENS, $expected);

        return intdiv($bounded * self::ANSWER_BOUND_PERCENT, 100);
    }

    /**
     * The answer bound plus a reasoning headroom, smaller when the connection suppresses reasoning. The headroom is a
     * ceiling, not a reservation.
     */
    public function outputBoundTokens(
        int $replyItemCount,
        RecommendationResponseSchema $schema,
        Reasoning $reasoning,
    ): int {
        return $this->answerBoundTokens($replyItemCount, $schema) + self::reasoningHeadroomTokens($reasoning);
    }

    private static function reasoningHeadroomTokens(Reasoning $reasoning): int
    {
        return match ($reasoning) {
            Reasoning::Suppressed => self::SUPPRESSED_REASONING_HEADROOM_TOKENS,
            Reasoning::Allowed => self::REASONING_HEADROOM_TOKENS,
        };
    }
}
