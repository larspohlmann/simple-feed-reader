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
