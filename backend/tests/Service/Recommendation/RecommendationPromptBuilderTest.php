<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\PromptLine;
use App\Service\Recommendation\RecommendationHistory;
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
        // 60 candidates at this window/picksLimit stay under budget in a single
        // batch (each truncated line is short); 150 reliably crosses it while
        // keeping the same window, picksLimit and description length.
        $candidateCount = 150;
        $candidates = array_map(
            static fn (int $id): PromptLine => self::line($id, "Candidate $id", 400),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(8192, 10));

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
        foreach ($batches as $batch) {
            self::assertGreaterThanOrEqual(10, \count($batch));
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
        self::assertStringContainsString('Include at most 100 picks', $system);

        self::assertStringContainsString(RecommendationPromptText::DEFAULT_GUIDANCE, $withoutGuidance[0]['content']);

        $user = $withGuidance[1]['content'];
        self::assertStringContainsString('FAVORITES (newest first):', $user);
        self::assertStringContainsString("KEPT (newest first):\n- none", $user);
        self::assertStringContainsString('- [7] ', $user);
    }

    public function testMergeMessagesCapPerBatch(): void
    {
        $winners = array_fill(0, 3, self::winnerBatch(10));
        $linesById = [];
        foreach ($winners as $batch) {
            foreach ($batch as $winner) {
                $linesById[$winner['id']] = self::line($winner['id'], "Title {$winner['id']}", 10);
            }
        }

        $messages = $this->builder->mergeMessages($winners, $linesById, $this->settings(32768, 6));

        $user = $messages[1]['content'];
        self::assertSame(12, substr_count($user, "\n- ["));
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
            \sprintf(RecommendationPromptText::OUTPUT_CONTRACT, 3),
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
        // Window 1565 with picksLimit 1 makes the budget just 1 token, so every
        // candidate after the first overflows it; only the >= MINIMUM_BATCH_SIZE
        // guard decides where each batch actually ends.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1565, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingResetsUsedTokensExactlyAtEachSplitBoundary(): void
    {
        // Window 1629 with picksLimit 1 puts the budget exactly one token below
        // where the 11th candidate line would land: a one-token error in either
        // the starting or the post-split reset of $used shifts the split point.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1629, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingBudgetIsSensitiveToEveryTermInItsFormula(): void
    {
        // Window 1630 with picksLimit 1 makes the budget land exactly on the
        // 11-candidate boundary: used+lineTokens equals the budget for the 12th
        // candidate, so the strict `>` (not `>=`) leaves it in the first batch,
        // and a sign error in subtracting the history tokens shifts the split.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1630, 1));

        self::assertSame([range(100, 110), range(111, 119)], $batches);
    }

    public function testPackingReservesAQuarterOfTheContextWindowWhenPicksLimitIsLarge(): void
    {
        // With picksLimit this large the response reserve is capped by
        // intdiv(contextWindow, 4), not by picksLimit itself: window 2119
        // reproduces the same 11/9 split as the picksLimit-bound budget test
        // above, but only because that divisor is exactly 4.
        $candidates = array_map(
            static fn (int $id): PromptLine => new PromptLine($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(2119, 100000));

        self::assertSame([range(100, 110), range(111, 119)], $batches);
    }

    public function testMergeMessagesReturnsTheExactRoleContentStructureAndUsesGuidance(): void
    {
        $winners = [[['id' => 1, 'reason' => 'Great fit']]];
        $linesById = [1 => new PromptLine(null, 'Winner Title', 'Feed A', '2026-01-05', null)];
        $settings = $this->settings(32768, 5, 'Focus on cats.');

        $messages = $this->builder->mergeMessages($winners, $linesById, $settings);

        $expectedSystem = implode("\n\n", [
            RecommendationPromptText::MERGE_ROLE,
            'Focus on cats.',
            \sprintf(RecommendationPromptText::OUTPUT_CONTRACT, 5),
        ]);

        self::assertSame(
            [
                ['role' => 'system', 'content' => $expectedSystem],
                ['role' => 'user', 'content' => "WINNERS:\n- [1] Winner Title — Feed A — 2026-01-05 — Great fit"],
            ],
            $messages,
        );
    }

    public function testMergeMessagesKeepsAtLeastOneWinnerPerBatchWhenTheCapWouldRoundToZero(): void
    {
        // 5 batches and picksLimit 1 make intdiv(2 * 1, 5) round down to 0;
        // max(1, ...) is what still keeps exactly one winner per batch.
        $winners = array_map(
            static fn (int $batchIndex): array => [
                ['id' => 100 + $batchIndex * 10, 'reason' => 'first'],
                ['id' => 101 + $batchIndex * 10, 'reason' => 'second'],
            ],
            range(0, 4),
        );
        $linesById = [];
        foreach ($winners as $batch) {
            foreach ($batch as $winner) {
                $linesById[$winner['id']] = new PromptLine(null, "T{$winner['id']}", 'F', 'D', null);
            }
        }

        $messages = $this->builder->mergeMessages($winners, $linesById, $this->settings(32768, 1));

        $user = $messages[1]['content'];
        self::assertSame(5, substr_count($user, "\n- ["));
        self::assertStringContainsString('- [100] ', $user);
        self::assertStringNotContainsString('- [101] ', $user);
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

    /**
     * @return list<array{id: int, reason: string}>
     */
    private static function winnerBatch(int $count): array
    {
        $winners = [];
        for ($id = 1; $id <= $count; ++$id) {
            $winners[] = ['id' => $id, 'reason' => "Reason $id"];
        }

        return $winners;
    }

    private function emptyHistory(): RecommendationHistory
    {
        return new RecommendationHistory(favorites: [], kept: [], viewed: []);
    }

    private function settings(
        int $contextWindow,
        int $picksLimit,
        ?string $guidancePrompt = null,
    ): EffectiveRecommendationSettings {
        return new EffectiveRecommendationSettings(
            guidancePrompt: $guidancePrompt,
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 1000,
            picksLimit: $picksLimit,
            contextWindow: $contextWindow,
            contextWindowSource: 'default',
            debugEnabled: false,
        );
    }
}
