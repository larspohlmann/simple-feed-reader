<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationAnswerBudget;
use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationAnswerBudgetTest extends TestCase
{
    /**
     * The reserve scales with the batch, because the batch is what the model
     * must answer about. A flat cap cannot be right for both: the default
     * pool packs to 45 items, but `batchCount` lets an account ask for one
     * batch of thousands, and a constant sized for the first silently
     * truncates the second.
     */
    public function testTheAnswerReserveScalesWithTheItemsBeingAnswered(): void
    {
        self::assertSame(3150, RecommendationAnswerBudget::answerTokenReserve(45));
        self::assertSame(35000, RecommendationAnswerBudget::answerTokenReserve(500));
    }

    /**
     * The packing estimate and the provider ceiling are different numbers.
     *
     * They were briefly the same one, and the packer read the ceiling's slack
     * as a real cost: at a 13000-token window the same pool went from 12
     * batches of 45 to 50 of 10, quadrupling the calls and the history re-sent
     * with each of them. The reserve must stay the honest estimate.
     */
    public function testTheProviderCeilingIsLooserThanThePackingEstimate(): void
    {
        $estimate = RecommendationAnswerBudget::answerTokenReserve(45);
        $ceiling = RecommendationAnswerBudget::answerBoundTokens(45, RecommendationResponseSchema::Ranking);

        self::assertGreaterThan($estimate, $ceiling);
        self::assertGreaterThanOrEqual(3017, $estimate, 'the estimate still covers the largest reply on record');
    }

    /**
     * A dedup reply is `{"duplicates":[…]}` — bare integers, no score and no
     * prose. Charging it the pick rate gave a reply that cannot legitimately
     * pass a few hundred tokens a ceiling of ten thousand, which is the
     * unbounded generation of #437 reintroduced on the dedup call.
     */
    public function testADedupReplyIsBoundedFarBelowARankingReply(): void
    {
        $dedup = RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::Duplicates);
        $ranking = RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::Ranking);

        self::assertLessThan(intdiv($ranking, 4), $dedup);
    }

    /**
     * A score-only batch reply is `{"id":123,"score":843}` — no `reason` — so
     * it is charged a fifth of the reason-bearing pick rate. Distillation
     * answers one profile string, so it is charged a fixed reserve regardless
     * of how many items informed it. Consolidation still writes a `reason` per
     * pick, so it keeps the full pick rate (#493).
     */
    public function testAnswerBoundIsSchemaAware(): void
    {
        self::assertSame(
            intdiv(max(1024, 100 * 15) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::BatchScore),
        );
        self::assertSame(
            intdiv(max(1024, 100 * 70) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::Consolidation),
        );
        self::assertSame(
            intdiv(max(1024, 1200) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(1, RecommendationResponseSchema::Distillation),
        );
    }

    /**
     * The reserve is now the whole bound on a connection that suppresses
     * reasoning (#437), so it has to cover a real reply rather than a
     * conservative guess at one. The largest reply this feature has produced
     * for a full batch ran to 12068 characters — roughly 3017 tokens, or 70
     * per item, because each pick carries a prose `reason`. At 40 tokens a
     * pick the reserve was under half of that, which the reasoning headroom
     * used to hide.
     */
    public function testTheAnswerReserveCoversTheLargestReplyAFullBatchHasProduced(): void
    {
        self::assertGreaterThanOrEqual(3017, RecommendationAnswerBudget::answerTokenReserve(45));
    }

    /**
     * Below the floor the per-item estimate under-counts: one pick's worth of
     * tokens does not cover a single item plus the JSON envelope around it.
     */
    public function testTheAnswerReserveNeverFallsBelowItsFloor(): void
    {
        self::assertSame(1024, RecommendationAnswerBudget::answerTokenReserve(1));
        self::assertSame(1024, RecommendationAnswerBudget::answerTokenReserve(0));
    }

    /**
     * A reasoning model bills reasoning against the same `max_tokens` as its
     * answer, so the provider budget adds a reasoning headroom on top of the
     * answer reserve. Without it a 45-item batch capped at 1800 tokens spent
     * its whole budget thinking and its JSON answer was truncated.
     */
    public function testTheProviderOutputReserveAddsReasoningHeadroomOnTopOfTheAnswer(): void
    {
        self::assertSame(
            RecommendationAnswerBudget::answerBoundTokens(45, RecommendationResponseSchema::Ranking) + 32000,
            RecommendationAnswerBudget::outputTokenReserve(45, RecommendationResponseSchema::Ranking),
        );
        self::assertSame(
            RecommendationAnswerBudget::answerBoundTokens(1, RecommendationResponseSchema::Ranking) + 32000,
            RecommendationAnswerBudget::outputTokenReserve(1, RecommendationResponseSchema::Ranking),
        );
    }
}
