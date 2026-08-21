<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What the provider is allowed to spend answering, per phase — split out of
 * RecommendationPromptBuilder (#493) so that class stays under PHPMD's
 * method-count and complexity ceilings now that it renders five prompt
 * shapes (batch, dedup, distillation, consolidation) rather than two. These
 * three methods never shared packBatches()'s own inline packing-budget
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
     * What one id costs in a dedup reply. That reply is `{"duplicates":[…]}`
     * — bare integers, no score and no prose — so it is nothing like a pick,
     * and charging it the pick rate put a 10000-token ceiling on a reply that
     * cannot legitimately exceed a few hundred (#437 follow-up).
     */
    private const int TOKENS_PER_DUPLICATE_ID = 8;

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
     * How many tokens a ranking answer over `$replyItemCount` items is
     * expected to need. packBatches() subtracts this from the context window
     * so the prompt leaves the model room to answer — and the answer is all
     * that competes with the prompt for the input context, so reasoning has no
     * place in this number.
     *
     * An expectation, not a ceiling: what the provider is allowed to spend is
     * answerBoundTokens(), which adds the slack this must not carry. Ranking
     * rather than schema-aware because packing only ever splits ranking
     * batches; the dedup phase sends one call over a pool it does not pack.
     *
     * The floor covers the JSON envelope and the short replies where a
     * per-item estimate under-counts: at one item, a per-pick rate would not
     * fit the punctuation around it, let alone the item.
     */
    public static function answerTokenReserve(int $replyItemCount): int
    {
        return max(self::MINIMUM_ANSWER_TOKENS, $replyItemCount * self::TOKENS_PER_PICK);
    }

    /**
     * What the provider may spend answering — the expected size plus slack,
     * for the phase whose reply shape `$schema` describes.
     *
     * Schema-aware because the two phases answer in different currencies. A
     * ranking pick carries prose; a dedup entry is a bare integer, and pricing
     * it as a pick handed a reply that cannot legitimately pass a few hundred
     * tokens a ceiling of ten thousand — reintroducing, on the dedup call, the
     * unbounded generation #437 removed from the batch call.
     */
    public static function answerBoundTokens(int $replyItemCount, RecommendationResponseSchema $schema): int
    {
        $expected = match ($schema) {
            RecommendationResponseSchema::Ranking => $replyItemCount * self::TOKENS_PER_PICK,
            RecommendationResponseSchema::Duplicates => $replyItemCount * self::TOKENS_PER_DUPLICATE_ID,
            RecommendationResponseSchema::Distillation => self::PROFILE_ANSWER_TOKENS,
            RecommendationResponseSchema::BatchScore => $replyItemCount * self::TOKENS_PER_SCORE_PICK,
            RecommendationResponseSchema::Consolidation => $replyItemCount * self::TOKENS_PER_PICK,
        };

        $bounded = max(self::MINIMUM_ANSWER_TOKENS, $expected);

        return intdiv($bounded * self::ANSWER_BOUND_PERCENT, 100);
    }

    /**
     * What the provider call sends as `max_tokens`, which caps total output —
     * reasoning plus answer for a reasoning model, not the answer alone. It is
     * the answer reserve plus a reasoning headroom, so a model that thinks
     * before it answers still has room to finish the JSON. #321 sent the answer
     * reserve here directly and starved reasoning models (#327); this is the
     * one place the two budgets legitimately diverge.
     */
    public static function outputTokenReserve(int $replyItemCount, RecommendationResponseSchema $schema): int
    {
        return self::answerBoundTokens($replyItemCount, $schema) + self::REASONING_HEADROOM_TOKENS;
    }

    /**
     * What the provider may spend on the whole output, for a connection that
     * does or does not suppress reasoning.
     *
     * The reasoning headroom pays for a thinking phase, and a connection that
     * suppresses reasoning has none. Paying for one anyway is not free: it is
     * the only bound that stops a model which has started to repeat itself, so
     * a 45-item batch that needs 1800 tokens was licensed to emit 33800, and a
     * 4B model looping on invented ids spent an hour reaching that ceiling
     * before the wall clock cut it (#437).
     *
     * RecommendationCompletionRequestFactory calls this directly rather than
     * branching on `suppressesReasoning()` itself, so the choice between the
     * two reserves lives beside the reserves it chooses between (#493).
     *
     * Adds the headroom itself rather than delegating to outputTokenReserve():
     * that method exists as its own tested unit, but forwarding through it
     * here would tunnel $replyItemCount three calls deep for no reader beyond
     * the last one, exactly the shape phptramp's tramp-data check exists to
     * catch.
     */
    public static function outputBoundTokens(
        int $replyItemCount,
        RecommendationResponseSchema $schema,
        bool $suppressesReasoning,
    ): int {
        $answerBound = self::answerBoundTokens($replyItemCount, $schema);

        return $suppressesReasoning ? $answerBound : $answerBound + self::REASONING_HEADROOM_TOKENS;
    }
}
