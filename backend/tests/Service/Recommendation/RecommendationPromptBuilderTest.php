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
     * A sanity check for the ordinary, cap-bound case: at a generous context
     * window the batch cap (the default maximumBatchSize of 45) binds before
     * either the old or the new token formula does, so a 200-candidate pool
     * packs into the minimum the cap allows either way. This does not exercise
     * the reserve/history-budget swap — see
     * testScoreOnlyBatchesPackLargerThanReasonBearingWouldHave for that.
     */
    public function testScoreOnlyBatchesFitTheCapBoundBatchCountAtAGenerousWindow(): void
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

        self::assertLessThanOrEqual(5, \count($batches));
    }

    /**
     * The batch call only ever asks for a score, not a reason, and its history
     * budget is the not-yet-distilled profile plus FAVORITES rather than the
     * full three-section history — both reserves are far smaller than the old
     * reason-bearing, three-section formula. An explicit batchCount of 1 keeps
     * the cap out of the way (ceil(200/1) = 200, same technique as
     * testAnExplicitBatchCountStillSplitsOnTheTokenBudget), so the token
     * budget alone decides the split here. At this window the old formula's
     * budget goes negative — a 70-token-per-pick reserve plus the full
     * three-section history outweighs it — so the packer falls back to
     * MINIMUM_BATCH_SIZE-sized batches, about 20 of them for 200 candidates.
     * The new formula's smaller score-only reserve and profile+FAVORITES-only
     * history budget stay positive, so the same pool packs into 5 near-full
     * batches instead (#493).
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
        $settings = $this->settings(10000, 50, batchCount: 1);

        $batches = $this->builder->packBatches($candidates, $history, $settings);

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
        // MAXIMUM_BATCH_SIZE cap (150) can be splitting these into 150/150/50.
        $candidateCount = 350;
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 10));

        self::assertSame([150, 150, 50], array_map('count', $batches));

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testPackingFiveHundredCandidatesIntoFourBatchesUnderTheDefaultCap(): void
    {
        // With MAXIMUM_BATCH_SIZE raised to 150 in #493 (score-only batches), the
        // default 500-candidate pool packs into 4 batches (3 × 150 + 1 × 50) under
        // a huge budget — a third of the 12 it took at the old reason-bearing cap.
        $candidateCount = 500;
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 50));

        self::assertCount(4, $batches);
        $batchSizes = array_map('count', $batches);
        self::assertSame([150, 150, 150, 50], $batchSizes);

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
        $withGuidance = $this->builder->batchMessages($history, $candidateLines, $settingsWithGuidance, null);
        $withoutGuidance = $this->builder->batchMessages(
            $history,
            $candidateLines,
            $this->settings(32768, 100),
            null,
        );

        $system = $withGuidance[0]['content'];
        self::assertStringContainsString(RecommendationPromptText::BATCH_SYSTEM_ROLE, $system);
        self::assertStringContainsString('Focus on cats.', $system);
        self::assertStringContainsString('Return one object for every candidate line', $system);

        self::assertStringContainsString(RecommendationPromptText::DEFAULT_GUIDANCE, $withoutGuidance[0]['content']);

        $user = $withGuidance[1]['content'];
        self::assertStringContainsString('FAVORITES (newest first):', $user);
        self::assertStringNotContainsString('KEPT', $user);
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
            null,
        )[0]['content'];

        self::assertStringContainsString('from 0 to 1000', $system);
        self::assertStringContainsString('Do not round to multiples of ten', $system);
        self::assertStringContainsString('"score": <0-1000>', $system);
        self::assertStringNotContainsString('0 to 100 ', $system);
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
            null,
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
            null,
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
            null,
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
            null,
        );

        self::assertStringNotContainsString('The full candidate set has', $messages[1]['content']);
    }

    /**
     * The batch call sees a not-yet-distilled PROFILE plus FAVORITES only —
     * KEPT and VIEWED stay in the history the distillation phase reads, but
     * never reach the batch prompt itself (#493). The reply is score-only:
     * the contract asks for "score" and not "reason".
     */
    public function testBatchMessagesCarryProfileAndFavouritesOnly(): void
    {
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10), self::line(2, 'Fav two', 10), self::line(3, 'Fav three', 10)],
            kept: [self::line(4, 'Kept one', 10), self::line(5, 'Kept two', 10), self::line(6, 'Kept three', 10)],
            viewed: [
                self::line(7, 'Viewed one', 10),
                self::line(8, 'Viewed two', 10),
                self::line(9, 'Viewed three', 10),
            ],
        );
        $candidateLines = [self::line(10, 'Candidate A', 10), self::line(11, 'Candidate B', 10)];

        $messages = $this->builder->batchMessages(
            $history,
            $candidateLines,
            $this->settings(32768, 100),
            'Likes homelab and Rust.',
        );

        $user = $messages[1]['content'];
        self::assertStringContainsString('PROFILE', $user);
        self::assertStringContainsString('Likes homelab and Rust.', $user);
        self::assertStringContainsString('FAVORITES', $user);
        self::assertStringNotContainsString('KEPT', $user);
        self::assertStringNotContainsString('VIEWED', $user);
        self::assertStringContainsString('"score"', $messages[0]['content']);
        self::assertStringNotContainsString('"reason"', $messages[0]['content']);
    }

    public function testBatchMessagesOmitProfileBlockWhenProfileIsNull(): void
    {
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10), self::line(2, 'Fav two', 10)],
            kept: [],
            viewed: [],
        );

        $messages = $this->builder->batchMessages(
            $history,
            [self::line(3, 'Candidate', 10)],
            $this->settings(32768, 100),
            null,
        );

        self::assertStringNotContainsString('PROFILE', $messages[1]['content']);
        self::assertStringContainsString('FAVORITES', $messages[1]['content']);
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

    /** The caller names the correction, so the consolidation phase can ask for its own thing back (#396). */
    public function testTheCorrectionIsTheOnePassedIn(): void
    {
        $messages = $this->builder->messagesWithCorrectiveTail(
            [['role' => 'system', 'content' => 'role']],
            '{"duplicates": [1]}',
            RecommendationPromptText::CONSOLIDATION_CORRECTIVE,
        );

        self::assertSame(RecommendationPromptText::CONSOLIDATION_CORRECTIVE, $messages[2]['content']);
    }

    public function testNoCorrectiveTailIsAppendedWithoutAnInvalidReply(): void
    {
        $messages = $this->builder->messagesWithCorrectiveTail(
            [['role' => 'system', 'content' => 'role']],
            null,
            RecommendationPromptText::CONSOLIDATION_CORRECTIVE,
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
            kept: [new PromptLine(null, 'Kept Title', 'Feed B', '2026-01-02', null)],
            viewed: [new PromptLine(null, 'View Title', 'Feed C', '2026-01-02', null)],
        );
        $candidateLines = [
            new PromptLine(5, 'Cand Title', 'Feed C', '2026-01-03', 'cand desc'),
            new PromptLine(null, 'No Id', 'Feed D', '2026-01-04', null),
        ];
        $settings = $this->settings(32768, 3);

        $messages = $this->builder->batchMessages($history, $candidateLines, $settings, 'Likes homelab.');

        $expectedSystem = implode("\n\n", [
            RecommendationPromptText::BATCH_SYSTEM_ROLE,
            RecommendationPromptText::DEFAULT_GUIDANCE,
            RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
        ]);
        $expectedUser = implode("\n\n", [
            "PROFILE:\nLikes homelab.",
            "FAVORITES (newest first):\n- Fav Title — Feed A — 2026-01-01 — fav desc",
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
        ], $this->settings(8192, 10), null);

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
            null,
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

    /**
     * An empty FAVORITES section is too small to make the sign of
     * ESTIMATED_PROFILE_TOKENS + tokens($favoritesSection) matter --
     * testPackingBudgetIsSensitiveToEveryTermInItsFormula's near-zero history
     * leaves a `+` and a `-` indistinguishable there. A real, sizeable
     * FAVORITES section makes the two diverge by thousands of tokens: a `-`
     * would inflate the budget instead of spending it, fitting every
     * candidate in one batch instead of two.
     */
    public function testHistoryTokensAreAddedToTheBudgetNotSubtracted(): void
    {
        $favorites = array_map(
            static fn (int $id): PromptLine => new PromptLine(
                $id,
                "Fav $id",
                'Feed',
                '2026-01-01',
                str_repeat('x', 200),
            ),
            range(1, 30),
        );
        $history = new RecommendationHistory(favorites: $favorites, kept: [], viewed: []);
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $history, $this->settings(4000, 1));

        self::assertSame([range(100, 109), range(110, 119)], $batches);
    }

    /**
     * With the default 45-candidate cap, responseReserve's `intdiv(..., 100)`
     * is pinned at the MINIMUM_ANSWER_TOKENS floor regardless of the exact
     * divisor, masking an off-by-one there. A larger cap (200, via
     * maximumBatchSize) pushes the raw quotient well above the floor, so a
     * 100 -> 101 or 100 -> 99 divisor shifts responseReserve enough to move
     * the batch boundary at this window.
     */
    public function testResponseReserveDivisorIsExactlyOneHundred(): void
    {
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 199),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(6850, 1, maximumBatchSize: 200),
        );

        self::assertSame([23, 23, 23, 23, 8], array_map('count', $batches));
    }

    /**
     * The distillation call is the one place the model sees the full,
     * three-section history: the batch and consolidation calls only ever see
     * the not-yet-distilled PROFILE plus FAVORITES (#493).
     */
    public function testDistillMessagesCarryAllThreeHistorySections(): void
    {
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10), self::line(2, 'Fav two', 10)],
            kept: [self::line(3, 'Kept one', 10), self::line(4, 'Kept two', 10)],
            viewed: [self::line(5, 'Viewed one', 10), self::line(6, 'Viewed two', 10)],
        );

        $messages = $this->builder->distillMessages($history, $this->settings(32768, 100));

        self::assertStringContainsString('FAVORITES', $messages[1]['content']);
        self::assertStringContainsString('KEPT', $messages[1]['content']);
        self::assertStringContainsString('VIEWED', $messages[1]['content']);
        self::assertStringContainsString('"profile"', $messages[0]['content']);
    }

    public function testDistillMessagesReturnsTheExactRoleContentStructure(): void
    {
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->distillMessages($history, $this->settings(32768, 100));

        $expectedSystem = RecommendationPromptText::DISTILL_ROLE
            . "\n\n" . RecommendationPromptText::DISTILL_OUTPUT_CONTRACT;
        $expectedUser = implode("\n\n", [
            "FAVORITES (newest first):\n- Fav one — Example Feed — 2026-08-01 — " . str_repeat('x', 10),
            "KEPT (newest first):\n- Kept one — Example Feed — 2026-08-01 — " . str_repeat('x', 10),
            "VIEWED (newest first):\n- Viewed one — Example Feed — 2026-08-01 — " . str_repeat('x', 10),
        ]);

        self::assertSame(
            [
                ['role' => 'system', 'content' => $expectedSystem],
                ['role' => 'user', 'content' => $expectedUser],
            ],
            $messages,
        );
    }

    /**
     * The consolidation call sees the same profile+FAVORITES fidelity as the
     * batch call, not the full history — KEPT and VIEWED never reach it — plus
     * the ranked shortlist rendered candidate-style so each line carries its id
     * (#493, Q6 correction).
     */
    public function testConsolidationMessagesCarryProfileFavouritesAndShortlist(): void
    {
        $pool = [['id' => 5, 'score' => 900, 'reason' => '']];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)];
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->consolidationMessages(
            $pool,
            $lines,
            $history,
            $this->settings(32768, 100),
            'Likes Rust.',
        );

        // Anchored to "PROFILE:\n" + the text, not merely both present, so a
        // mutant that reverses the concatenation order still fails this.
        self::assertStringContainsString("PROFILE:\nLikes Rust.", $messages[1]['content']);
        self::assertStringContainsString('FAVORITES', $messages[1]['content']);
        self::assertStringNotContainsString('KEPT', $messages[1]['content']);
        self::assertStringNotContainsString('VIEWED', $messages[1]['content']);
        self::assertStringContainsString('[5]', $messages[1]['content']);
        self::assertStringContainsString('Rust 2.0 released', $messages[1]['content']);
        self::assertStringContainsString('duplicates', $messages[0]['content']);
        // CONSOLIDATION_ROLE also mentions "duplicates" on its own, so this
        // pins the OUTPUT_CONTRACT half specifically — a mutant that drops it
        // from the concatenation must not pass on the ROLE text alone.
        self::assertStringContainsString('Reply with JSON only, no prose', $messages[0]['content']);
        // The 0-1000 calibration must survive: the local model scores on a
        // 0-100 scale without the explicit bands + three-digit anchor + the
        // anti-0-100 guard, which stored every reason at a tenth of its value.
        self::assertStringContainsString('900-1000', $messages[0]['content']);
        self::assertStringContainsString('do not score on a 0-100 scale', $messages[0]['content']);
        self::assertStringContainsString('Score every candidate line, never leave one out', $messages[0]['content']);
    }

    public function testConsolidationMessagesReturnsTheExactRoleContentStructure(): void
    {
        $pool = [['id' => 5, 'score' => 900, 'reason' => '']];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)];
        $history = new RecommendationHistory(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->consolidationMessages(
            $pool,
            $lines,
            $history,
            $this->settings(32768, 100),
            'Likes Rust.',
        );

        $expectedSystem = RecommendationPromptText::CONSOLIDATION_ROLE
            . "\n\n" . RecommendationPromptText::CONSOLIDATION_OUTPUT_CONTRACT;
        $expectedUser = implode("\n\n", [
            "PROFILE:\nLikes Rust.",
            "FAVORITES (newest first):\n- Fav one — Example Feed — 2026-08-01 — " . str_repeat('x', 10),
            "CANDIDATES (1 posts — return 1 objects, one per line):\n"
                . '- [5] Rust 2.0 released — Example Feed — 2026-08-01 — ' . str_repeat('x', 10),
        ]);

        self::assertSame(
            [
                ['role' => 'system', 'content' => $expectedSystem],
                ['role' => 'user', 'content' => $expectedUser],
            ],
            $messages,
        );
    }

    /**
     * A pool entry whose line has since been pruned (id absent from
     * $linesById) must be dropped from the rendered shortlist, not carried
     * through as a null — candidateLine() is typed to PromptLine and would
     * fatal on one.
     */
    public function testConsolidationMessagesDropsAPrunedPoolEntryFromTheShortlist(): void
    {
        $pool = [
            ['id' => 5, 'score' => 900, 'reason' => ''],
            ['id' => 6, 'score' => 500, 'reason' => ''],
        ];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)]; // 6 pruned since its batch ran

        $messages = $this->builder->consolidationMessages(
            $pool,
            $lines,
            $this->emptyHistory(),
            $this->settings(32768, 100),
            null,
        );

        self::assertStringContainsString(
            'CANDIDATES (1 posts — return 1 objects, one per line):',
            $messages[1]['content'],
        );
        self::assertStringContainsString('[5]', $messages[1]['content']);
        self::assertStringNotContainsString('[6]', $messages[1]['content']);
    }

    public function testConsolidationMessagesRejectsAnEmptyPool(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The consolidation phase requires at least one ranked winner.');

        $this->builder->consolidationMessages([], [], $this->emptyHistory(), $this->settings(32768, 100), null);
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
