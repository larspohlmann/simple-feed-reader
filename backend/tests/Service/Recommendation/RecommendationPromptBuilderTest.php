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
     * The reserve scales with the batch, because the batch is what the model
     * must answer about. A flat cap cannot be right for both: the default
     * pool packs to 45 items, but `batchCount` lets an account ask for one
     * batch of thousands, and a constant sized for the first silently
     * truncates the second.
     */
    public function testTheAnswerReserveScalesWithTheItemsBeingAnswered(): void
    {
        self::assertSame(1800, $this->builder->answerTokenReserve(45));
        self::assertSame(20000, $this->builder->answerTokenReserve(500));
    }

    /**
     * Below the floor the per-item estimate under-counts: 40 tokens does not
     * cover a single item plus the JSON envelope around it.
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
            $this->builder->answerTokenReserve(45) + 32000,
            $this->builder->outputTokenReserve(45),
        );
        self::assertSame(
            $this->builder->answerTokenReserve(1) + 32000,
            $this->builder->outputTokenReserve(1),
        );
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
        self::assertStringContainsString('Score every candidate.', $system);

        self::assertStringContainsString(RecommendationPromptText::DEFAULT_GUIDANCE, $withoutGuidance[0]['content']);

        $user = $withGuidance[1]['content'];
        self::assertStringContainsString('FAVORITES (newest first):', $user);
        self::assertStringContainsString("KEPT (newest first):\n- none", $user);
        self::assertStringContainsString('- [7] ', $user);
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
        self::assertLessThan(strpos($user, 'CANDIDATES:'), strpos($user, 'The full candidate set has'));
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
        $tail = $this->builder->correctiveTail('not json');

        self::assertSame(
            [
                ['role' => 'assistant', 'content' => 'not json'],
                ['role' => 'user', 'content' => RecommendationPromptText::CORRECTIVE],
            ],
            $tail,
        );
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
            "CANDIDATES:\n- [5] Cand Title — Feed C — 2026-01-03 — cand desc\n- [0] No Id — Feed D — 2026-01-04",
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
            1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', null),
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
                    'content' => "RANKED (best first):\n"
                        . "- [2] Title Two — Feed B — 2026-01-06 — Strong match\n"
                        . '- [1] Title One — Feed A — 2026-01-05 — Loose match',
                ],
            ],
            $messages,
        );
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
    ): EffectiveRecommendationSettings {
        return new EffectiveRecommendationSettings(
            guidancePrompt: $guidancePrompt,
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 500,
            picksLimit: $picksLimit,
            packing: new RecommendationPackingSettings(
                contextWindow: $contextWindow,
                contextWindowSource: 'default',
                batchCount: $batchCount,
            ),
            debugEnabled: false,
        );
    }
}
