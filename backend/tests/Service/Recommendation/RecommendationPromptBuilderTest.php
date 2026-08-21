<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CandidatePoolSummary;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\PromptLine;
use App\Service\Recommendation\RecommendationHistory;
use App\Service\Recommendation\RecommendationPackingSettings;
use App\Service\Recommendation\RecommendationPromptBuilder;
use App\Service\Recommendation\RecommendationPromptText;
use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationPromptBuilderTest extends TestCase
{
    private RecommendationPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RecommendationPromptBuilder();
    }

    public function testDescriptionLengthScalesAndClamps(): void
    {
        self::assertSame(120, $this->builder->descriptionLength(8192));
        self::assertSame(239, $this->builder->descriptionLength(32768));
        self::assertSame(480, $this->builder->descriptionLength(200000));
    }

    /**
     * The reserve scales with the batch, because the batch is what the model
     * must answer about. A flat cap cannot be right for both: the default
     * pool packs to 45 items, but `batchCount` lets an account ask for one
     * batch of thousands, and a constant sized for the first silently
     * truncates the second.
     */
    public function testTheAnswerReserveScalesWithTheItemsBeingAnswered(): void
    {
        self::assertSame(3150, $this->builder->answerTokenReserve(45));
        self::assertSame(35000, $this->builder->answerTokenReserve(500));
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
        $estimate = $this->builder->answerTokenReserve(45);
        $ceiling = $this->builder->answerBoundTokens(45, RecommendationResponseSchema::Ranking);

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
        $dedup = $this->builder->answerBoundTokens(100, RecommendationResponseSchema::Duplicates);
        $ranking = $this->builder->answerBoundTokens(100, RecommendationResponseSchema::Ranking);

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
            $this->builder->answerBoundTokens(100, RecommendationResponseSchema::BatchScore),
        );
        self::assertSame(
            intdiv(max(1024, 100 * 70) * 150, 100),
            $this->builder->answerBoundTokens(100, RecommendationResponseSchema::Consolidation),
        );
        self::assertSame(
            intdiv(max(1024, 1200) * 150, 100),
            $this->builder->answerBoundTokens(1, RecommendationResponseSchema::Distillation),
        );
    }

    /**
     * The quote goes into a JSON request body. `substr` would cut a multi-byte
     * sequence in half and make `json_encode` fail, costing the retry the clip
     * exists to enable — and this reader's feeds are German.
     */
    public function testAClippedReplyIsStillValidUtf8(): void
    {
        $tail = $this->builder->correctiveTail(str_repeat('ü', 3000), 'Try again.');

        self::assertTrue(mb_check_encoding($tail[0]['content'], 'UTF-8'));
        // The round trip is the real proof: json_encode refuses malformed
        // UTF-8, which is how a byte-clipped umlaut would break the retry.
        self::assertSame(
            $tail[0]['content'],
            json_decode(json_encode($tail[0]['content'], \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The batch ceiling is no longer one constant for every endpoint (#437).
     * A small local model asked to hold 45 entries in order is at the edge of
     * what it can do, and the failure mode is a repetition loop rather than a
     * wrong ranking, so the connection's own ceiling decides the split.
     */
    public function testThePackerHonoursTheCeilingItsSettingsCarry(): void
    {
        $candidates = array_map(
            static fn (int $index): PromptLine => new PromptLine($index, 't', 'f', 'd', null),
            range(1, 100),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(1_000_000, 50, maximumBatchSize: 30),
        );

        self::assertSame([30, 30, 30, 10], array_map('count', $batches));
    }

    /**
     * There is nothing to correct against when the reply is empty, and a
     * blocking-shape runaway produces exactly that: the body never parsed, so
     * the partial answer is ''. Appending it anyway put an empty assistant turn
     * in the retry beside a correction referring to a reply the model cannot
     * see — a retry no different from the attempt that failed (#437 review).
     */
    public function testAnEmptyReplyAddsNoCorrectiveTail(): void
    {
        $messages = [['role' => 'user', 'content' => 'rank these']];

        self::assertSame($messages, $this->builder->messagesWithCorrectiveTail($messages, '', 'Try again.'));
        self::assertSame($messages, $this->builder->messagesWithCorrectiveTail($messages, "  \n ", 'Try again.'));
    }

    /**
     * The tail quotes the model's own reply back so it can see what was wrong
     * with it. A reply that ran away is the case that breaks: echoing tens of
     * kilobytes of a repetition loop spends the context on it and re-primes
     * the very loop the retry exists to break (#437). The head carries the
     * whole signal.
     */
    public function testTheCorrectiveTailClipsAReplyTooLongToQuoteBack(): void
    {
        $tail = $this->builder->correctiveTail(str_repeat('{"id": 349500}, ', 4000), 'Try again.');

        self::assertLessThan(4096, \strlen($tail[0]['content']));
        self::assertStringContainsString('{"id": 349500}', $tail[0]['content']);
    }

    /**
     * The ordinary unusable reply is short, and it is quoted whole: clipping
     * is for the runaway, not a general shortening of the correction.
     */
    public function testTheCorrectiveTailQuotesAShortReplyWhole(): void
    {
        $tail = $this->builder->correctiveTail('not json', 'Try again.');

        self::assertSame('not json', $tail[0]['content']);
    }

    /**
     * The limit itself is quoted whole. A reply exactly at the bound is not a
     * runaway, and clipping it would cost the correction its last characters
     * for nothing.
     */
    public function testTheCorrectiveTailQuotesAReplyAtTheLimitWhole(): void
    {
        $atTheLimit = str_repeat('a', 2000);

        $tail = $this->builder->correctiveTail($atTheLimit, 'Try again.');

        self::assertSame($atTheLimit, $tail[0]['content']);
    }

    /**
     * One character past it is clipped to the limit, and the marker says so —
     * without it the model reads a reply that appears to end mid-token as its
     * own complete previous answer.
     */
    public function testAClippedReplyIsCutToTheLimitAndSaysSo(): void
    {
        $tail = $this->builder->correctiveTail(str_repeat('a', 2001), 'Try again.');

        self::assertStringStartsWith(str_repeat('a', 2000), $tail[0]['content']);
        self::assertStringContainsString('truncated', $tail[0]['content']);
        self::assertStringNotContainsString(str_repeat('a', 2001), $tail[0]['content']);
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
        self::assertGreaterThanOrEqual(3017, $this->builder->answerTokenReserve(45));
    }

    /**
     * Below the floor the per-item estimate under-counts: one pick's worth of
     * tokens does not cover a single item plus the JSON envelope around it.
     */
    public function testTheAnswerReserveNeverFallsBelowItsFloor(): void
    {
        self::assertSame(1024, $this->builder->answerTokenReserve(1));
        self::assertSame(1024, $this->builder->answerTokenReserve(0));
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
            $this->builder->answerBoundTokens(45, RecommendationResponseSchema::Ranking) + 32000,
            $this->builder->outputTokenReserve(45, RecommendationResponseSchema::Ranking),
        );
        self::assertSame(
            $this->builder->answerBoundTokens(1, RecommendationResponseSchema::Ranking) + 32000,
            $this->builder->outputTokenReserve(1, RecommendationResponseSchema::Ranking),
        );
    }

    /**
     * The batch call only ever asks for a score, not a reason, and its history
     * budget is the not-yet-distilled profile plus FAVORITES rather than the
     * full three-section history — both reserves are far smaller than the old
     * reason-bearing, three-section formula, so a 200-candidate pool that used
     * to need many small batches now packs into a handful of full ones (#493).
     */
    public function testScoreOnlyBatchesPackLargerThanReasonBearingWouldHave(): void
    {
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 100),
            range(1, 200),
        );
        $history = new RecommendationHistory(
            favorites: array_map(static fn (int $id): PromptLine => self::line($id, "Favorite $id", 100), range(1, 40)),
            kept: array_map(static fn (int $id): PromptLine => self::line($id, "Kept $id", 100), range(1, 40)),
            viewed: array_map(static fn (int $id): PromptLine => self::line($id, "Viewed $id", 100), range(1, 80)),
        );
        $settings = $this->settings(32768, 50);

        $batches = $this->builder->packBatches($candidates, $history, $settings);

        // score-only reply (15/pick) + favorites-only history budget => far fewer, fuller batches
        self::assertLessThanOrEqual(5, \count($batches));
    }

    public function testEverythingFitsInOneBatchWhenSmall(): void
    {
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 50),
            range(1, 20),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(32768, 100));

        self::assertCount(1, $batches);
        self::assertSame(range(1, 20), $batches[0]);
    }

    public function testPackingSplitsWhenTheBudgetOverflows(): void
    {
        // 35 candidates at this window/picksLimit split into 20 + 15 purely on
        // the token budget: both resulting batches stay well under
        // MAXIMUM_BATCH_SIZE (40), so the cap plays no part in the split — only
        // the budget does. A window of 8192 (as this test used before the cap
        // existed) fits all 35 in one batch, so the window was shrunk instead
        // of the candidate count grown, keeping the split budget-driven rather
        // than cap-driven. The window is raised by the constant reserve (1600)
        // to keep the same budget now that the reserve no longer scales with
        // picksLimit.
        $candidateCount = 35;
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 400),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(4029, 10));

        self::assertGreaterThan(1, \count($batches));
        foreach ($batches as $batch) {
            // Below the 40-candidate cap, so the split above is proven to come
            // from the token budget, not from the cap.
            self::assertLessThan(40, \count($batch));
        }

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testPackingCapsBatchSizeEvenWhenTheBudgetWouldAllowMore(): void
    {
        // A huge window and short lines mean the token budget never binds —
        // every candidate would fit in one batch on budget alone. Only the
        // MAXIMUM_BATCH_SIZE cap can be splitting these into 45/45/10.
        $candidateCount = 100;
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 10));

        self::assertSame([45, 45, 10], array_map('count', $batches));

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testPackingFiveHundredCandidatesIntoTwelveBatchesUnderNewDefaults(): void
    {
        // With MAXIMUM_BATCH_SIZE raised to 45 in #321, the default 500-candidate
        // pool packs into 12 batches (11 × 45 + 1 × 5) under a huge budget.
        // The 12 packing calls plus one dedup call make 13 total provider calls —
        // half the 26 needed under the old 40-candidate cap.
        $candidateCount = 500;
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 50));

        self::assertCount(12, $batches);
        $batchSizes = array_map('count', $batches);
        self::assertSame([45, 45, 45, 45, 45, 45, 45, 45, 45, 45, 45, 5], $batchSizes);

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testAnExplicitBatchCountReplacesTheDefaultCapUnderAHugeBudget(): void
    {
        // A huge window means the token budget never binds, so an explicit
        // batchCount of 12 is the only thing that can produce 12 batches of
        // at most ceil(500 / 12) = 42.
        $candidateCount = 500;
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(1_000_000, 50, batchCount: 12),
        );

        self::assertCount(12, $batches);
        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(42, \count($batch));
        }

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testAnExplicitBatchCountStillSplitsOnTheTokenBudget(): void
    {
        // batchCount = 1 asks for a single batch, but a small context window
        // cannot hold every candidate: the token budget below still forces a
        // split, proving the expert override does not bypass it.
        $candidateCount = 60;
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 400),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(4096, 10, batchCount: 1),
        );

        self::assertGreaterThan(1, \count($batches));

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testTinyWindowStillMakesProgress(): void
    {
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 400),
            range(1, 60),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(4096, 100));

        self::assertNotSame([], $batches);
        // All batches except the final one should respect MINIMUM_BATCH_SIZE.
        for ($i = 0; $i < \count($batches) - 1; ++$i) {
            self::assertGreaterThanOrEqual(10, \count($batches[$i]));
        }
    }

    public function testBatchMessagesLayerFixedGuidanceAndContract(): void
    {
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Favorite', 10)],
            kept: [],
            viewed: [self::line(2, 'Viewed', 10)],
        );
        $candidateLines = [self::line(7, 'Candidate seven', 10)];

        $settingsWithGuidance = $this->settings(32768, 100, 'Focus on cats.');
        $withGuidance = $this->builder->batchMessages($history, $candidateLines, $settingsWithGuidance);
        $withoutGuidance = $this->builder->batchMessages($history, $candidateLines, $this->settings(32768, 100));

        $system = $withGuidance[0]['content'];
        self::assertStringContainsString(RecommendationPromptText::SYSTEM_ROLE, $system);
        self::assertStringContainsString('Focus on cats.', $system);
        self::assertStringContainsString('Return one object for every candidate line', $system);

        self::assertStringContainsString(RecommendationPromptText::DEFAULT_GUIDANCE, $withoutGuidance[0]['content']);

        $user = $withGuidance[1]['content'];
        self::assertStringContainsString('FAVORITES (newest first):', $user);
        self::assertStringContainsString("KEPT (newest first):\n- none", $user);
        self::assertStringContainsString('- [7] ', $user);
    }

    /**
     * The model quantises: 29 of one run's 50 picks scored exactly 85, and the
     * prose asking it to separate near-equals made it worse, not better. The
     * scale is now 0-1000 and the prompt says outright what to do with the
     * room (#403).
     */
    public function testTheRubricAsksForExactValuesOnAThousandPointScale(): void
    {
        $system = $this->builder->batchMessages(
            $this->emptyHistory(),
            [self::line(7, 'Candidate seven', 10)],
            $this->settings(32768, 100),
        )[0]['content'];

        self::assertStringContainsString('from 0 to 1000', $system);
        self::assertStringContainsString('Do not round to multiples of ten', $system);
        self::assertStringContainsString('"score": <0-1000>', $system);
        self::assertStringNotContainsString('0 to 100 ', $system);
    }

    /**
     * A single viewed post became "a fascination for sports events" and scored
     * 920, and 13 of that run's 50 picks came out as sports. The prompt held
     * VIEWED down with an ordering and no number behind it, which a small model
     * drops as soon as the topical match is good. The ceiling is a number now,
     * inside the band the rubric already reserves for a weak link (#440).
     */
    public function testAViewedOnlyMatchIsGivenANumericCeiling(): void
    {
        $system = $this->builder->batchMessages(
            $this->emptyHistory(),
            [self::line(7, 'Candidate seven', 10)],
            $this->settings(32768, 100),
        )[0]['content'];

        self::assertStringContainsString(
            "A candidate whose only support in the reader's history is a VIEWED post scores below 400",
            $system,
        );
        self::assertStringContainsString('opening a post is not liking it', $system);
    }

    /**
     * The batch prompt asked for every candidate and, in the same breath, told
     * the model to omit the duplicates of a story it had already scored. It
     * resolved the conflict by omitting: 3.2% of production candidates were
     * never scored, and an unscored candidate can never be recommended (#399).
     * Duplicates belong to the dedup phase, which sees the whole ranked list
     * rather than one random sample of it.
     */
    public function testTheBatchPromptNeverAsksForACandidateToBeLeftOut(): void
    {
        $system = $this->builder->batchMessages(
            $this->emptyHistory(),
            [self::line(7, 'Candidate seven', 10)],
            $this->settings(32768, 100),
        )[0]['content'];

        self::assertStringContainsString('never leave a candidate out', $system);
        self::assertStringNotContainsString('omit the others', $system);
    }

    /**
     * The count is the model's own check on "return one object per line", and
     * it counts the lines rendered into this batch -- not the pool, and not the
     * batch cap (#399, and the same reasoning as the dedup frame in #396).
     */
    public function testTheCandidateHeaderNamesHowManyLinesTheBatchHolds(): void
    {
        $candidateLines = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'Title ' . $id, 'Feed', '2026-01-05', null),
            range(1, 17),
        );

        $user = $this->builder->batchMessages(
            $this->emptyHistory(),
            $candidateLines,
            $this->settings(32768, 100),
        )[1]['content'];

        self::assertStringContainsString('CANDIDATES (17 posts — return 17 objects, one per line):', $user);
    }

    public function testBatchMessagesAddsThePoolFrameLineWhenASummaryIsPassed(): void
    {
        $candidateLines = [self::line(7, 'Candidate seven', 10)];
        $summary = new CandidatePoolSummary(total: 2000, oldest: '2026-01-15', newest: '2026-08-09');

        $messages = $this->builder->batchMessages(
            $this->emptyHistory(),
            $candidateLines,
            $this->settings(32768, 100),
            $summary,
        );

        $user = $messages[1]['content'];
        self::assertStringContainsString(
            'The full candidate set has 2000 posts spanning 2026-01-15 to 2026-08-09. '
                . 'This batch is a random sample of that set.',
            $user,
        );
        // The frame sits before the candidate lines it frames.
        self::assertLessThan(strpos($user, 'CANDIDATES ('), strpos($user, 'The full candidate set has'));
    }

    public function testBatchMessagesOmitsThePoolFrameLineWhenNoSummaryIsPassed(): void
    {
        $messages = $this->builder->batchMessages(
            $this->emptyHistory(),
            [self::line(7, 'Candidate seven', 10)],
            $this->settings(32768, 100),
        );

        self::assertStringNotContainsString('The full candidate set has', $messages[1]['content']);
    }

    public function testCorrectiveTailEchoesTheInvalidReply(): void
    {
        $tail = $this->builder->correctiveTail('not json', RecommendationPromptText::CORRECTIVE);

        self::assertSame(
            [
                ['role' => 'assistant', 'content' => 'not json'],
                ['role' => 'user', 'content' => RecommendationPromptText::CORRECTIVE],
            ],
            $tail,
        );
    }

    /** The caller names the correction, so the dedup phase can ask for its own thing back (#396). */
    public function testTheCorrectionIsTheOnePassedIn(): void
    {
        $messages = $this->builder->messagesWithCorrectiveTail(
            [['role' => 'system', 'content' => 'role']],
            '{"duplicates": [1]}',
            RecommendationPromptText::DEDUP_CORRECTIVE,
        );

        self::assertSame(RecommendationPromptText::DEDUP_CORRECTIVE, $messages[2]['content']);
    }

    public function testNoCorrectiveTailIsAppendedWithoutAnInvalidReply(): void
    {
        $messages = $this->builder->messagesWithCorrectiveTail(
            [['role' => 'system', 'content' => 'role']],
            null,
            RecommendationPromptText::DEDUP_CORRECTIVE,
        );

        self::assertCount(1, $messages);
    }

    public function testPackBatchesFallsBackToZeroForACandidateWithoutAnEntryId(): void
    {
        $candidates = [new PromptLine(null, 'No Id', 'Feed D', '2026-01-04', null)];

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(32768, 10));

        self::assertSame([[0]], $batches);
    }

    public function testBatchMessagesReturnsTheExactRoleContentStructure(): void
    {
        $history = new RecommendationHistory(
            favorites: [new PromptLine(null, 'Fav Title', 'Feed A', '2026-01-01', 'fav desc')],
            kept: [],
            viewed: [new PromptLine(null, 'View Title', 'Feed B', '2026-01-02', null)],
        );
        $candidateLines = [
            new PromptLine(5, 'Cand Title', 'Feed C', '2026-01-03', 'cand desc'),
            new PromptLine(null, 'No Id', 'Feed D', '2026-01-04', null),
        ];
        $settings = $this->settings(32768, 3);

        $messages = $this->builder->batchMessages($history, $candidateLines, $settings);

        $expectedSystem = implode("\n\n", [
            RecommendationPromptText::SYSTEM_ROLE,
            RecommendationPromptText::DEFAULT_GUIDANCE,
            RecommendationPromptText::OUTPUT_CONTRACT,
        ]);
        $expectedUser = implode("\n\n", [
            "FAVORITES (newest first):\n- Fav Title — Feed A — 2026-01-01 — fav desc",
            "KEPT (newest first):\n- none",
            "VIEWED (newest first):\n- View Title — Feed B — 2026-01-02",
            "CANDIDATES (2 posts — return 2 objects, one per line):\n"
                . "- [5] Cand Title — Feed C — 2026-01-03 — cand desc\n"
                . '- [0] No Id — Feed D — 2026-01-04',
        ]);

        self::assertSame(
            [
                ['role' => 'system', 'content' => $expectedSystem],
                ['role' => 'user', 'content' => $expectedUser],
            ],
            $messages,
        );
    }

    public function testDescriptionAtExactlyTheClampedLengthIsNotTruncated(): void
    {
        // Two-byte characters make mb_strlen and strlen disagree: this is the
        // boundary the `<=` clamp and the mb_-prefixed length check both guard.
        $exactly120 = str_repeat('é', 120);
        $exactly121 = str_repeat('é', 121);

        $messages = $this->builder->batchMessages($this->emptyHistory(), [
            new PromptLine(1, 'Boundary120', 'F', 'D', $exactly120),
            new PromptLine(2, 'Boundary121', 'F', 'D', $exactly121),
        ], $this->settings(8192, 10));

        $user = $messages[1]['content'];
        self::assertStringContainsString("- [1] Boundary120 — F — D — {$exactly120}\n", $user);
        self::assertStringEndsWith('- [2] Boundary121 — F — D — ' . str_repeat('é', 120) . '…', $user);
    }

    public function testTruncationCutsAtTheStartByCharacterNotByte(): void
    {
        // A varying multi-byte sequence makes a byte-offset substr (instead of
        // mb_substr) or an off-by-one start offset produce different text.
        $characters = ['á', 'é', 'í', 'ó', 'ú'];
        $description = '';
        for ($i = 0; $i < 130; ++$i) {
            $description .= $characters[$i % 5];
        }
        $expectedTruncated = mb_substr($description, 0, 120) . '…';

        $messages = $this->builder->batchMessages(
            $this->emptyHistory(),
            [new PromptLine(9, 'Varying', 'F', 'D', $description)],
            $this->settings(8192, 10),
        );

        self::assertStringContainsString("- [9] Varying — F — D — {$expectedTruncated}", $messages[1]['content']);
    }

    public function testPackingSplitsExactlyAtTheMinimumBatchSizeWhenTheBudgetOverflowsEarly(): void
    {
        // Window 3125 (raised by the constant reserve of 1600) with picksLimit 1
        // makes the budget just 1 token, so every candidate after the first
        // overflows it; only the >= MINIMUM_BATCH_SIZE guard decides where each
        // batch actually ends.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3125, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingResetsUsedTokensExactlyAtEachSplitBoundary(): void
    {
        // Window 3189 (raised by the constant reserve of 1600) with picksLimit 1
        // puts the budget exactly one token below where the 11th candidate line
        // would land: a one-token error in either the starting or the
        // post-split reset of $used shifts the split point.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3189, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingBudgetIsSensitiveToEveryTermInItsFormula(): void
    {
        // Window 3195 (raised by the constant reserve of 1600) with picksLimit 1
        // makes the budget land exactly on the 10-candidate boundary (shifted down
        // one candidate due to the new responseReserve = 45 * 1 instead of 40 * 1):
        // used+lineTokens equals the budget for the 11th candidate, so the
        // strict `>` (not `>=`) leaves it in the first batch, and a sign error
        // in subtracting the history tokens shifts the split.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3195, 1));

        self::assertSame([range(100, 109), range(110, 119)], $batches);
    }

    public function testDedupMessagesReturnsTheExactRoleContentStructureWithoutGuidance(): void
    {
        $rankedPool = [
            ['id' => 2, 'score' => 90, 'reason' => 'Strong match'],
            ['id' => 1, 'score' => 40, 'reason' => 'Loose match'],
        ];
        $linesById = [
            1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', 'One opens like this'),
            2 => new PromptLine(2, 'Title Two', 'Feed B', '2026-01-06', null),
        ];

        $messages = $this->builder->dedupMessages($rankedPool, $linesById);

        self::assertSame(
            [
                [
                    'role' => 'system',
                    'content' => RecommendationPromptText::DEDUP_ROLE
                        . "\n\n" . RecommendationPromptText::DEDUP_OUTPUT_CONTRACT,
                ],
                [
                    'role' => 'user',
                    'content' => 'This list holds 2 entries. Most lists hold few duplicates and many hold none, '
                        . 'so expect to name none or a handful. Never name more than 1 of them: a reply naming '
                        . 'more is discarded whole, and the reader is then shown the list with its real '
                        . "duplicates still in it.\n\n"
                        . "RANKED (best first):\n"
                        . "- [2] Title Two — 2026-01-06\n"
                        . '- [1] Title One — 2026-01-05 — One opens like this',
                ],
            ],
            $messages,
        );
    }

    /**
     * The ceiling the prompt names is the one the parser enforces, and it
     * counts the lines the model actually sees -- a pool entry whose line has
     * gone missing is not in the list and must not raise the number (#396).
     */
    public function testTheDedupFrameNamesTheCeilingTheParserEnforces(): void
    {
        $rankedPool = array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 50, 'reason' => 'match'],
            range(1, 21),
        );
        $linesById = [];
        foreach (range(1, 20) as $id) {
            $linesById[$id] = new PromptLine($id, 'Title ' . $id, 'Feed', '2026-01-05', null);
        }

        $user = $this->builder->dedupMessages($rankedPool, $linesById)[1]['content'];

        self::assertStringContainsString('This list holds 20 entries.', $user);
        self::assertStringContainsString('Never name more than 10 of them', $user);
    }

    /**
     * The dedup call renders every line into one prompt, so a per-line
     * description budget multiplies straight into it. 250 characters is
     * enough to tell one event from another and is fixed rather than scaled
     * to the context window (#406).
     */
    public function testDedupLinesCarryTheDescriptionCutToAFixedLength(): void
    {
        $description = str_repeat('x', 400);
        $messages = $this->builder->dedupMessages(
            [['id' => 1, 'score' => 90, 'reason' => 'unused here']],
            [1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', $description)],
        );

        $user = $messages[1]['content'];
        self::assertStringContainsString('- [1] Title One — 2026-01-05 — ' . str_repeat('x', 250) . '…', $user);
        self::assertStringNotContainsString('unused here', $user);
        self::assertStringNotContainsString('Feed A', $user);
    }

    public function testDedupMessagesSkipsAPoolEntryWhoseLineIsMissing(): void
    {
        $rankedPool = [
            ['id' => 1, 'score' => 90, 'reason' => 'Present'],
            ['id' => 2, 'score' => 80, 'reason' => 'Pruned'],
        ];
        $linesById = [1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', null)];

        $messages = $this->builder->dedupMessages($rankedPool, $linesById);

        self::assertStringContainsString('- [1] ', $messages[1]['content']);
        self::assertStringNotContainsString('- [2] ', $messages[1]['content']);
    }

    public function testDedupMessagesRejectsAnEmptyPool(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The dedup phase requires at least one ranked winner.');

        $this->builder->dedupMessages([], []);
    }

    private static function line(int $id, string $title, int $descriptionChars): PromptLine
    {
        return new PromptLine(
            entryId: $id,
            title: $title,
            feedName: 'Example Feed',
            date: '2026-08-01',
            description: str_repeat('x', $descriptionChars),
        );
    }

    private function emptyHistory(): RecommendationHistory
    {
        return new RecommendationHistory(favorites: [], kept: [], viewed: []);
    }

    private function settings(
        int $contextWindow,
        int $picksLimit,
        ?string $guidancePrompt = null,
        ?int $batchCount = null,
        int $maximumBatchSize = RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
    ): EffectiveRecommendationSettings {
        return new EffectiveRecommendationSettings(
            guidancePrompt: $guidancePrompt,
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 500,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: $picksLimit,
            packing: new RecommendationPackingSettings(
                contextWindow: $contextWindow,
                contextWindowSource: 'default',
                batchCount: $batchCount,
                maximumBatchSize: $maximumBatchSize,
            ),
            debugEnabled: false,
        );
    }
}
