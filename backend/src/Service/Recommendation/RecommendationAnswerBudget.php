<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What the provider is allowed to spend answering, per phase — split out of
 * RecommendationPromptBuilder (#493) so that class stays under PHPMD's
 * method-count and complexity ceilings now that it renders three prompt
 * shapes (batch, distillation, consolidation) rather than two. These
 * methods never shared packBatches()'s own inline packing-budget
 * arithmetic — they only shared three of its numeric constants, which are
 * duplicated here rather than made to depend on RecommendationPromptBuilder
 * for three integers.
 */
final readonly class RecommendationAnswerBudget
{
    /**
     * What one scored pick costs in the reply: its id, its score, and the
     * prose `reason` that dominates the three. Measured, not guessed — the
     * largest full-batch reply on record ran to 12068 characters for 43 items,
     * about 70 tokens each.
     *
     * This is an estimate of a real reply and nothing else. packBatches()
     * subtracts it from the context window, so a number above the truth costs
     * prompt budget and splits the pool into more batches than it needs; a
     * number below the truth lets a prompt crowd out the answer it asked for.
     * It was 40 — under half of the measured cost — until #437.
     *
     * The slack a runaway is stopped by is NOT in here. #437 first raised this
     * to 100 to serve as both, and the packer read the inflation as a real
     * cost: at a 13000-token window the same pool went from 12 batches of 45
     * to 50 of 10, quadrupling the calls and the history re-sent with them.
     * The two numbers are separate because the two jobs are.
     */
    private const int TOKENS_PER_PICK = 70;

    /**
     * Duplicated from RecommendationPromptBuilder's own copy on purpose: that
     * class's packBatches() uses it for the packing budget, a different
     * computation from the provider bound calculated here, and coupling the
     * two classes for one shared integer would cost more than the duplication
     * does (#493).
     */
    private const int TOKENS_PER_SCORE_PICK = 15;

    /** The answer reserve for the distillation reply. One `{"profile": "..."}` string of at most
     *  ~300 words; sized generously so a reasoning model still finishes the JSON (#493). */
    private const int PROFILE_ANSWER_TOKENS = 1200;

    /**
     * How much room over the estimate the provider is actually given.
     *
     * The estimate is a mean; a reply that runs long is not a runaway and must
     * not be truncated into one that cannot parse. Half again covers the
     * spread and still leaves the ceiling an order of magnitude below the
     * 33800 tokens that let a looping model generate for an hour.
     *
     * Duplicated from RecommendationPromptBuilder's own copy for the same
     * reason as TOKENS_PER_SCORE_PICK (#493).
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    /**
     * Duplicated from RecommendationPromptBuilder's own copy for the same
     * reason as TOKENS_PER_SCORE_PICK (#493).
     */
    private const int MINIMUM_ANSWER_TOKENS = 1024;

    /**
     * A reasoning model's thinking is billed against the same `max_tokens` as
     * its answer, and can legitimately run to tens of thousands of tokens
     * before the JSON begins. This headroom rides on top of the answer reserve
     * so `max_tokens` bounds reasoning-plus-answer, not the answer alone —
     * without it a 45-item batch capped at 1800 tokens spent the whole budget
     * thinking and its answer was truncated (deepseek-flash, #327). Generous
     * and finite on purpose: a runaway model is still cut off here, and the
     * wall clock and wire cap sit behind it.
     */
    private const int REASONING_HEADROOM_TOKENS = 32000;

    /**
     * What the provider may spend answering — the expected size plus slack,
     * for the phase whose reply shape `$schema` describes.
     *
     * Schema-aware because each phase answers in a different currency. A
     * batch-score entry is a bare id-and-score pair; a consolidation pick
     * carries prose; distillation answers a single profile string regardless
     * of how many items informed it. Pricing a batch reply at the
     * reason-bearing rate would multiply its answer budget for nothing, the
     * mistake #437 fixed for the batch call.
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
     * a reasoning headroom, always -- independent of whether the connection
     * suppresses reasoning.
     *
     * suppressReasoning sends `reasoning: {effort: none}` to the provider as a
     * hint, but a local reasoning model routinely thinks regardless, and a
     * suppressed connection used to get the answer reserve alone: once the
     * score-only batch cap rose to 150 (#493), a full answer already filled
     * ~92% of that reserve, so the model's unbidden thinking guillotined the
     * answer at finish_reason: length. The headroom is a ceiling, not a
     * reservation: a model that honours the hint emits only the answer and
     * stops early (finish_reason: stop), spending nothing on the unused room --
     * so keeping it costs a compliant connection nothing while a non-compliant
     * one no longer truncates. The 32000 ceiling remains the runaway bound,
     * with the wall clock and wire cap behind it (supersedes the #437 cut,
     * which starved local models that ignore the hint).
     */
    public static function outputBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int
    {
        return self::answerBoundTokens($replyItemCount, $schema) + self::REASONING_HEADROOM_TOKENS;
    }
}
