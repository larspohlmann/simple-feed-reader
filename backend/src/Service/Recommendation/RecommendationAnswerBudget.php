<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What the provider is allowed to spend answering, per phase — split out of
 * RecommendationPromptBuilder (#493) to keep that class under PHPMD's
 * method-count and complexity ceilings now it renders three prompt shapes
 * (batch, distillation, consolidation). Three of its numeric constants are
 * duplicated here rather than couple the classes for three integers.
 */
final readonly class RecommendationAnswerBudget
{
    /**
     * What one scored pick costs in the reply: id, score, and the prose
     * `reason` that dominates. Measured — the largest full-batch reply ran
     * 12068 characters for 43 items, ~70 tokens each. packBatches() subtracts
     * it from the context window, so too high splits the pool into needless
     * batches and too low lets the prompt crowd out the answer (was 40 until
     * #437). It is an estimate only, not the runaway slack: #437 first raised
     * it to 100 to serve as both, and the packer read the inflation as real
     * cost (a 13000-token window went from 12 batches of 45 to 50 of 10). The
     * two numbers are separate because the two jobs are.
     */
    private const int TOKENS_PER_PICK = 70;

    /**
     * Duplicated from RecommendationPromptBuilder on purpose: its packBatches()
     * uses it for the packing budget, a different computation from the provider
     * bound here, and coupling two classes for one integer costs more than the
     * duplication (#493).
     */
    private const int TOKENS_PER_SCORE_PICK = 15;

    /** The answer reserve for the distillation reply. One `{"profile": "..."}` string of at most
     *  ~300 words; sized generously so a reasoning model still finishes the JSON (#493). */
    private const int PROFILE_ANSWER_TOKENS = 1200;

    /**
     * How much room over the estimate the provider is given. The estimate is a
     * mean; a long reply is not a runaway and must not be truncated into one
     * that cannot parse. Half again covers the spread yet stays an order of
     * magnitude below the 33800 tokens that let a looping model run an hour.
     * Duplicated from RecommendationPromptBuilder for the same reason as
     * TOKENS_PER_SCORE_PICK (#493).
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    /**
     * Duplicated from RecommendationPromptBuilder's own copy for the same
     * reason as TOKENS_PER_SCORE_PICK (#493).
     */
    private const int MINIMUM_ANSWER_TOKENS = 1024;

    /**
     * A reasoning model's thinking is billed against the same `max_tokens` as
     * its answer and can run tens of thousands of tokens before the JSON. This
     * rides on top of the answer reserve so `max_tokens` bounds reasoning plus
     * answer: without it a 45-item batch capped at 1800 tokens spent the budget
     * thinking and truncated its answer (deepseek-flash, #327). Finite — a
     * runaway is still cut off, with the wall clock and wire cap behind it.
     */
    private const int REASONING_HEADROOM_TOKENS = 32000;

    /**
     * The reasoning headroom kept even when a connection suppresses reasoning.
     * The `reasoning: {effort: none}` hint does not stop a local model thinking
     * — qwen3.7-flash spent ~1900 tokens on hidden reasoning_content (#493).
     * None guillotined the answer at finish_reason: length once batches grew;
     * the full 32000 made suppress meaningless. This middle, a quarter of the
     * full ceiling, leaves room for the thinking that slips through while
     * suppress still bounds the spend.
     */
    private const int SUPPRESSED_REASONING_HEADROOM_TOKENS = 8000;

    /**
     * What the provider may spend answering — expected size plus slack, for the
     * phase whose reply shape `$schema` describes. Schema-aware because each
     * phase answers in a different currency: a batch-score entry is an id-score
     * pair, a consolidation pick carries prose, distillation answers one profile
     * string regardless of item count. Pricing a batch at the reason-bearing
     * rate would multiply its budget for nothing, the mistake #437 fixed.
     */
    public static function answerBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int
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
     * What the provider may spend on the whole output: the answer reserve plus
     * a reasoning headroom sized by whether the connection suppresses reasoning.
     * A connection that may reason gets the full headroom (#327); a suppressed
     * one gets a reduced headroom, not none, because the hint does not stop a
     * local model thinking and the answer reserve alone truncated it once
     * batches grew (#493). The headroom is a ceiling, not a reservation, so a
     * model that honours the hint stops early and spends nothing on the unused
     * room; both ceilings, plus the wall clock and wire cap, still stop a runaway.
     */
    public static function outputBoundTokens(
        int $replyItemCount,
        RecommendationResponseSchema $schema,
        bool $suppressesReasoning,
    ): int {
        return self::answerBoundTokens($replyItemCount, $schema)
            + self::reasoningHeadroomTokens($suppressesReasoning);
    }

    /**
     * The reasoning headroom `outputBoundTokens()` adds — a fixed cost that does
     * not scale with the reply's item count. Exposed so the consolidation sizer
     * can reserve the same room against the context window it must fit within.
     */
    public static function reasoningHeadroomTokens(bool $suppressesReasoning): int
    {
        return $suppressesReasoning
            ? self::SUPPRESSED_REASONING_HEADROOM_TOKENS
            : self::REASONING_HEADROOM_TOKENS;
    }
}
