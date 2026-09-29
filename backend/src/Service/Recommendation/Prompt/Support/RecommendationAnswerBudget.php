<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Support;

use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Recommendation\Prompt\Model\RecommendationResponseSchema;

/**
 * What the provider may spend answering, per phase. RecommendationPromptBuilder::packBatches() reserves this same
 * bound for a batch reply, so the packer and the provider cannot disagree.
 */
final readonly class RecommendationAnswerBudget
{
    /**
     * What one scored consolidation pick costs in the reply: id, score, and the dominant prose
     * `reason`, ~70 tokens measured (#437). consolidationInputSize() subtracts it when sizing the
     * shortlist against the context window, so too high shrinks the shortlist and too low crowds out the answer.
     */
    private const int TOKENS_PER_PICK = 70;

    /** A score-only batch pick, `{"id":123,"score":843}`: about a fifth of a reasoned one (#493). */
    private const int TOKENS_PER_SCORE_PICK = 15;

    /** The answer reserve for the distillation reply. One `{"profile": "..."}` string of at most
     *  ~300 words; sized generously so a reasoning model still finishes the JSON (#493). */
    private const int PROFILE_ANSWER_TOKENS = 1200;

    /**
     * Half again over the mean estimate: a long reply is not a runaway to truncate into one that cannot parse, and
     * the bound stays an order of magnitude below the 33800 tokens that let a looping model run an hour.
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    private const int MINIMUM_ANSWER_TOKENS = 1024;

    /**
     * A reasoning model's thinking is billed against the same `max_tokens` as
     * its answer and can run tens of thousands of tokens before the JSON. This
     * rides on top of the answer reserve so `max_tokens` bounds reasoning plus
     * answer — without it a 45-item batch capped at 1800 tokens spent the
     * budget thinking and truncated its answer (deepseek-flash, #327). Still
     * finite: a runaway is cut off, with the wall clock and wire cap behind it.
     */
    private const int REASONING_HEADROOM_TOKENS = 32000;

    /**
     * The reasoning headroom kept even when a connection suppresses reasoning.
     * The `reasoning: {effort: none}` hint does not stop a local model
     * thinking — qwen3.7-flash spent ~1900 tokens on hidden reasoning_content
     * (#493). Zero headroom guillotined the answer at finish_reason: length
     * once batches grew, and the full 32000 made suppress meaningless; this
     * quarter-size middle leaves room for the thinking that slips through
     * while suppress still bounds the spend.
     */
    private const int SUPPRESSED_REASONING_HEADROOM_TOKENS = 8000;

    /**
     * What the provider may spend answering — expected size plus slack, for
     * the phase whose reply shape `$schema` describes. Schema-aware because
     * each phase answers in a different currency: a batch-score entry is an
     * id-score pair, a consolidation pick carries prose, distillation answers
     * one profile string regardless of item count. Pricing a batch at the
     * reason-bearing rate would multiply its budget for nothing (#437).
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
     * The answer bound plus a reasoning headroom. Suppression only shrinks the headroom: the hint does not stop a
     * local model thinking (#493), and the headroom is a ceiling, not a reservation (#327).
     */
    public static function outputBoundTokens(
        int $replyItemCount,
        RecommendationResponseSchema $schema,
        Reasoning $reasoning,
    ): int {
        return self::answerBoundTokens($replyItemCount, $schema) + self::reasoningHeadroomTokens($reasoning);
    }

    private static function reasoningHeadroomTokens(Reasoning $reasoning): int
    {
        return match ($reasoning) {
            Reasoning::Suppressed => self::SUPPRESSED_REASONING_HEADROOM_TOKENS,
            Reasoning::Allowed => self::REASONING_HEADROOM_TOKENS,
        };
    }
}
