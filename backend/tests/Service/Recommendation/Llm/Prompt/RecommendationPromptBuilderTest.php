<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Enum\RecommendationBatchSize;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget;
use App\Service\Recommendation\Llm\Prompt\RecommendationPromptBuilder;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationPromptBuilderTest extends TestCase
{
    private RecommendationPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RecommendationPromptBuilder(new RecommendationAnswerBudget());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function contextWindows(): iterable
    {
        yield 'small window' => [8192];
        yield 'large window' => [131072];
    }

    #[DataProvider('contextWindows')]
    public function testADescriptionLongerThanTheFixedLengthIsClippedWhateverTheWindow(int $contextWindow): void
    {
        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings($contextWindow, 10), null),
            [new ArticleLineModel(1, 'Long', 'F', 'D', str_repeat('a', 1000) . 'LOST')],
        );

        self::assertStringEndsWith('- [1] Long — F — D — ' . str_repeat('a', 1000) . '…', $messages[1]['content']);
    }

    #[DataProvider('contextWindows')]
    public function testADescriptionShorterThanTheFixedLengthIsKeptWholeWhateverTheWindow(int $contextWindow): void
    {
        $description = str_repeat('b', 996) . 'END';
        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings($contextWindow, 10), null),
            [new ArticleLineModel(1, 'Short', 'F', 'D', $description)],
        );

        self::assertStringEndsWith('- [1] Short — F — D — ' . $description, $messages[1]['content']);
    }

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

    public function testThePackerHonoursTheCeilingItsSettingsCarry(): void
    {
        $candidates = array_map(
            static fn (int $index): ArticleLineModel => new ArticleLineModel($index, 't', 'f', 'd', null),
            range(1, 100),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(1_000_000, 50, maximumBatchSize: 30),
        );

        self::assertSame([30, 30, 30, 10], array_map('count', $batches));
    }

    public function testAnEmptyReplyAddsNoCorrectiveTail(): void
    {
        $messages = [['role' => 'user', 'content' => 'rank these']];

        self::assertSame($messages, $this->builder->messagesWithCorrectiveTail($messages, '', 'Try again.'));
        self::assertSame($messages, $this->builder->messagesWithCorrectiveTail($messages, "  \n ", 'Try again.'));
    }

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

    public function testScoreOnlyBatchesFitTheCapBoundBatchCountAtAGenerousWindow(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 100),
            range(1, 200),
        );
        $history = new RecommendationHistoryModel(
            favorites: array_map(
                static fn (int $id): ArticleLineModel => self::line($id, "Favorite $id", 100),
                range(1, 40),
            ),
            kept: array_map(static fn (int $id): ArticleLineModel => self::line($id, "Kept $id", 100), range(1, 40)),
            viewed: array_map(
                static fn (int $id): ArticleLineModel => self::line($id, "Viewed $id", 100),
                range(1, 80),
            ),
        );
        $settings = $this->settings(32768, 50);

        $batches = $this->builder->packBatches($candidates, $history, $settings);

        self::assertLessThanOrEqual(5, \count($batches));
    }

    /**
     * A Large batch size lifts the cap to 200, past these 200 candidates, so the token budget alone splits them. The
     * score-only reserve and the profile-plus-FAVORITES history leave room for at most five batches.
     */
    public function testScoreOnlyBatchesPackLargerThanReasonBearingWouldHave(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 100),
            range(1, 200),
        );
        $history = new RecommendationHistoryModel(
            favorites: array_map(
                static fn (int $id): ArticleLineModel => self::line($id, "Favorite $id", 100),
                range(1, 40),
            ),
            kept: array_map(static fn (int $id): ArticleLineModel => self::line($id, "Kept $id", 100), range(1, 40)),
            viewed: array_map(
                static fn (int $id): ArticleLineModel => self::line($id, "Viewed $id", 100),
                range(1, 80),
            ),
        );
        $settings = $this->settings(
            RecommendationPromptBuilder::ESTIMATED_PROFILE_TOKENS + 9300,
            50,
            batchSize: RecommendationBatchSize::Large,
        );

        $batches = $this->builder->packBatches($candidates, $history, $settings);

        self::assertLessThanOrEqual(5, \count($batches));
    }

    public function testPackingBudgetsByTheClippedDescriptionNotTheRawOne(): void
    {
        $tenThousandChars = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 10000),
            range(1, 60),
        );
        $thousandChars = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 1000),
            range(1, 60),
        );
        $settings = $this->settings(RecommendationPromptBuilder::ESTIMATED_PROFILE_TOKENS + 7492, 50);

        $clipped = $this->builder->packBatches($tenThousandChars, $this->emptyHistory(), $settings);
        $whole = $this->builder->packBatches($thousandChars, $this->emptyHistory(), $settings);

        self::assertSame($whole, $clipped);
        self::assertSame([14, 14, 14, 14, 4], array_map('count', $clipped));
    }

    public function testEverythingFitsInOneBatchWhenSmall(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 50),
            range(1, 20),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(32768, 100));

        self::assertCount(1, $batches);
        self::assertSame(range(1, 20), $batches[0]);
    }

    public function testPackingSplitsWhenTheBudgetOverflows(): void
    {
        // Window 4029 leaves a budget below zero, so these 35 candidates split into MINIMUM_BATCH_SIZE batches, far
        // below the cap of 100: the budget splits them, not the cap.
        $candidateCount = 35;
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 400),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(4029, 10));

        self::assertGreaterThan(1, \count($batches));
        foreach ($batches as $batch) {
            self::assertLessThan(40, \count($batch));
        }

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testPackingCapsBatchSizeEvenWhenTheBudgetWouldAllowMore(): void
    {
        // A huge window and short lines keep the token budget from binding: only the batch cap (100) splits these.
        $candidateCount = 250;
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 10));

        self::assertSame([100, 100, 50], array_map('count', $batches));

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testPackingFiveHundredCandidatesIntoFiveBatchesUnderTheDefaultCap(): void
    {
        $candidateCount = 500;
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(1_000_000, 50));

        self::assertCount(5, $batches);
        $batchSizes = array_map('count', $batches);
        self::assertSame([100, 100, 100, 100, 100], $batchSizes);

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testConsolidationInputSizeFillsToTheCeilingOnALargeContext(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav', 10)],
            kept: [],
            viewed: [],
        );

        // A 1M-token context easily holds 6 × picksLimit lines plus the reply,
        // so the ceiling (CONSOLIDATION_MAX_INPUT_FACTOR × picksLimit) binds.
        $size = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(1_000_000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );

        self::assertSame(300, $size);
    }

    public function testConsolidationInputSizeFloorsOnATightContext(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav', 10)],
            kept: [],
            viewed: [],
        );

        // At a 32k context the 32k reasoning headroom alone overruns the window above the floor, so the size falls
        // back to CONSOLIDATION_MIN_INPUT_FACTOR × picksLimit.
        $size = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(32768, 50), 'A profile.'),
            Reasoning::Allowed,
        );

        self::assertSame(100, $size);
    }

    public function testConsolidationInputSizeGrowsWithTheContextWindow(): void
    {
        $history = new RecommendationHistoryModel(favorites: [self::line(1, 'Fav', 10)], kept: [], viewed: []);

        $small = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(60000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );
        $large = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(1_000_000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );

        self::assertGreaterThan($small, $large);
        self::assertGreaterThanOrEqual(100, $small);
        self::assertLessThanOrEqual(300, $large);
    }

    public function testALongerProfileShrinksTheConsolidationShortlist(): void
    {
        $history = new RecommendationHistoryModel(favorites: [self::line(1, 'Fav', 10)], kept: [], viewed: []);

        $short = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(60000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );
        $long = $this->builder->consolidationInputSize(
            new PromptContext($history, $this->settings(60000, 50), str_repeat('Likes Rust. ', 400)),
            Reasoning::Suppressed,
        );

        self::assertLessThan($short, $long);
    }

    public function testMoreFavoritesShrinkTheConsolidationShortlist(): void
    {
        $few = new RecommendationHistoryModel(favorites: [self::line(1, 'Fav', 10)], kept: [], viewed: []);
        $many = new RecommendationHistoryModel(
            favorites: array_map(static fn (int $id): ArticleLineModel => self::line($id, 'Fav', 100), range(1, 40)),
            kept: [],
            viewed: [],
        );

        $withFew = $this->builder->consolidationInputSize(
            new PromptContext($few, $this->settings(60000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );
        $withMany = $this->builder->consolidationInputSize(
            new PromptContext($many, $this->settings(60000, 50), 'A profile.'),
            Reasoning::Suppressed,
        );

        self::assertLessThan($withFew, $withMany);
    }

    /**
     * A huge window means the token budget never binds, so only the cap splits
     * the pool. Small halves the 100 automatic ceiling and Large doubles it, so
     * 250 candidates pack into five 50s or into 200 + 50.
     *
     * @param list<int> $expectedBatchSizes
     */
    #[DataProvider('sizeScalingCases')]
    public function testBatchSizeScalesTheDefaultCapUnderAHugeBudget(
        RecommendationBatchSize $batchSize,
        array $expectedBatchSizes,
    ): void {
        $candidateCount = 250;
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, "C$id", 'F', 'D', null),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(1_000_000, 50, batchSize: $batchSize),
        );

        self::assertSame($expectedBatchSizes, array_map('count', $batches));
        self::assertSame(range(1, $candidateCount), array_merge(...$batches));
    }

    /**
     * @return iterable<string, array{RecommendationBatchSize, list<int>}>
     */
    public static function sizeScalingCases(): iterable
    {
        yield 'small halves the ceiling' => [RecommendationBatchSize::Small, [50, 50, 50, 50, 50]];
        yield 'large doubles the ceiling' => [RecommendationBatchSize::Large, [200, 50]];
    }

    public function testTheTokenBudgetStillSplitsRegardlessOfBatchSize(): void
    {
        // Large lifts the cap to 200, past these 60 candidates, but a small
        // context window cannot hold them all: the token budget below still
        // forces a split, proving the size choice does not bypass it.
        $candidateCount = 60;
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 400),
            range(1, $candidateCount),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(4096, 10, batchSize: RecommendationBatchSize::Large),
        );

        self::assertGreaterThan(1, \count($batches));

        $ids = array_merge(...$batches);
        self::assertSame(range(1, $candidateCount), $ids);
    }

    public function testTinyWindowStillMakesProgress(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => self::line($id, "Candidate $id", 400),
            range(1, 60),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(4096, 100));

        self::assertNotSame([], $batches);
        // All batches except the final one should respect MINIMUM_BATCH_SIZE.
        for ($index = 0; $index < \count($batches) - 1; ++$index) {
            self::assertGreaterThanOrEqual(10, \count($batches[$index]));
        }
    }

    public function testBatchMessagesLayerFixedGuidanceAndContract(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Favorite', 10)],
            kept: [],
            viewed: [self::line(2, 'Viewed', 10)],
        );
        $candidateLines = [self::line(7, 'Candidate seven', 10)];

        $settingsWithGuidance = $this->settings(32768, 100, 'Focus on cats.');
        $withGuidance = $this->builder->batchMessages(
            new PromptContext($history, $settingsWithGuidance, null),
            $candidateLines,
        );
        $withoutGuidance = $this->builder->batchMessages(
            new PromptContext($history, $this->settings(32768, 100), null),
            $candidateLines,
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

    public function testTheRubricAsksForExactValuesOnAThousandPointScale(): void
    {
        $system = $this->builder->batchMessages(
            $this->defaultContext(),
            [self::line(7, 'Candidate seven', 10)],
        )[0]['content'];

        self::assertStringContainsString('from 0 to 1000', $system);
        self::assertStringContainsString('Do not round to multiples of ten', $system);
        self::assertStringContainsString('"score": <0-1000>', $system);
        self::assertStringNotContainsString('0 to 100 ', $system);
    }

    /**
     * An unscored candidate can never be recommended, so the batch prompt never asks for one to be left out:
     * duplicates are the consolidation phase's to find.
     */
    public function testTheBatchPromptNeverAsksForACandidateToBeLeftOut(): void
    {
        $system = $this->builder->batchMessages(
            $this->defaultContext(),
            [self::line(7, 'Candidate seven', 10)],
        )[0]['content'];

        self::assertStringContainsString('never leave a candidate out', $system);
        self::assertStringNotContainsString('omit the others', $system);
    }

    /** The count is of the lines rendered into this batch, not the pool and not the batch cap. */
    public function testTheCandidateHeaderNamesHowManyLinesTheBatchHolds(): void
    {
        $candidateLines = array_map(
            static fn (int $id): ArticleLineModel
                => new ArticleLineModel($id, 'Title ' . $id, 'Feed', '2026-01-05', null),
            range(1, 17),
        );

        $user = $this->builder->batchMessages(
            $this->defaultContext(),
            $candidateLines,
        )[1]['content'];

        self::assertStringContainsString('CANDIDATES (17 posts — return 17 objects, one per line):', $user);
    }

    public function testBatchMessagesAddsThePoolFrameLineWhenASummaryIsPassed(): void
    {
        $candidateLines = [self::line(7, 'Candidate seven', 10)];
        $summary = new CandidatePoolSummaryModel(total: 2000, oldest: '2026-01-15', newest: '2026-08-09');

        $messages = $this->builder->batchMessages(
            $this->defaultContext(),
            $candidateLines,
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
            $this->defaultContext(),
            [self::line(7, 'Candidate seven', 10)],
        );

        self::assertStringNotContainsString('The full candidate set has', $messages[1]['content']);
    }

    public function testBatchMessagesCarryProfileAndFavouritesOnly(): void
    {
        $history = new RecommendationHistoryModel(
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
            new PromptContext($history, $this->settings(32768, 100), 'Likes homelab and Rust.'),
            $candidateLines,
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
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav one', 10), self::line(2, 'Fav two', 10)],
            kept: [],
            viewed: [],
        );

        $messages = $this->builder->batchMessages(
            new PromptContext($history, $this->settings(32768, 100), null),
            [self::line(3, 'Candidate', 10)],
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

    public function testBatchMessagesReturnsTheExactRoleContentStructure(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [new ArticleLineModel(101, 'Fav Title', 'Feed A', '2026-01-01', 'fav desc')],
            kept: [new ArticleLineModel(102, 'Kept Title', 'Feed B', '2026-01-02', null)],
            viewed: [new ArticleLineModel(103, 'View Title', 'Feed C', '2026-01-02', null)],
        );
        $candidateLines = [
            new ArticleLineModel(5, 'Cand Title', 'Feed C', '2026-01-03', 'cand desc'),
            new ArticleLineModel(6, 'Second', 'Feed D', '2026-01-04', null),
        ];
        $settings = $this->settings(32768, 3);

        $messages = $this->builder->batchMessages(
            new PromptContext($history, $settings, 'Likes homelab.'),
            $candidateLines,
        );

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
                . '- [6] Second — Feed D — 2026-01-04',
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
        $exactly1000 = str_repeat('é', 1000);
        $exactly1001 = str_repeat('é', 1001);

        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings(8192, 10), null),
            [
                new ArticleLineModel(1, 'Boundary1000', 'F', 'D', $exactly1000),
                new ArticleLineModel(2, 'Boundary1001', 'F', 'D', $exactly1001),
            ],
        );

        $user = $messages[1]['content'];
        self::assertStringContainsString("- [1] Boundary1000 — F — D — {$exactly1000}\n", $user);
        self::assertStringEndsWith('- [2] Boundary1001 — F — D — ' . str_repeat('é', 1000) . '…', $user);
    }

    public function testTruncationCutsAtTheStartByCharacterNotByte(): void
    {
        // A varying multi-byte sequence makes a byte-offset substr (instead of
        // mb_substr) or an off-by-one start offset produce different text.
        $characters = ['á', 'é', 'í', 'ó', 'ú'];
        $description = '';
        for ($index = 0; $index < 1010; ++$index) {
            $description .= $characters[$index % 5];
        }
        $expectedTruncated = mb_substr($description, 0, 1000) . '…';

        $messages = $this->builder->batchMessages(
            new PromptContext($this->emptyHistory(), $this->settings(8192, 10), null),
            [new ArticleLineModel(9, 'Varying', 'F', 'D', $description)],
        );

        self::assertStringContainsString("- [9] Varying — F — D — {$expectedTruncated}", $messages[1]['content']);
    }

    public function testPackingSplitsExactlyAtTheMinimumBatchSizeWhenTheBudgetOverflowsEarly(): void
    {
        // Window 3125 leaves a budget below zero, so every candidate overflows it: only the >= MINIMUM_BATCH_SIZE
        // guard decides where each batch ends.
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3125, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingResetsUsedTokensExactlyAtEachSplitBoundary(): void
    {
        // Window 3189 also leaves a budget below zero, so each split falls at MINIMUM_BATCH_SIZE whatever $used
        // holds: this pins the batch boundaries, not the reset of $used.
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 124),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3189, 1));

        self::assertSame([range(100, 109), range(110, 119), range(120, 124)], $batches);
    }

    public function testPackingBudgetIsSensitiveToEveryTermInItsFormula(): void
    {
        // Window 3195 leaves a budget below zero, so the minimum batch size splits 10 and 10. Flipping the sign
        // of any term (overhead, reply reserve, history) lifts it past the 120 tokens that fit all 20 in one batch.
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $this->emptyHistory(), $this->settings(3195, 1));

        self::assertSame([range(100, 109), range(110, 119)], $batches);
    }

    /**
     * A sizeable FAVORITES section, because an empty one cannot tell `+` from `-` in ESTIMATED_PROFILE_TOKENS +
     * tokens($favoritesSection): a `-` would inflate the budget and fit every candidate in one batch instead of two.
     */
    public function testHistoryTokensAreAddedToTheBudgetNotSubtracted(): void
    {
        $favorites = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel(
                $id,
                "Fav $id",
                'Feed',
                '2026-01-01',
                str_repeat('x', 200),
            ),
            range(1, 30),
        );
        $history = new RecommendationHistoryModel(favorites: $favorites, kept: [], viewed: []);
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 119),
        );

        $batches = $this->builder->packBatches($candidates, $history, $this->settings(4000, 1));

        self::assertSame([range(100, 109), range(110, 119)], $batches);
    }

    /**
     * A maximumBatchSize of 200 keeps RecommendationAnswerBudget's `expected` well above the
     * MINIMUM_ANSWER_TOKENS floor, so a wrong ANSWER_BOUND_PERCENT/100 divisor would shift the split.
     */
    public function testResponseReserveDivisorIsExactlyOneHundred(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 199),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(RecommendationPromptBuilder::ESTIMATED_PROFILE_TOKENS + 6150, 1, maximumBatchSize: 200),
        );

        self::assertSame([23, 23, 23, 23, 8], array_map('count', $batches));
    }

    /**
     * Below the 1024-token floor the provider may spend the floor plus half (RecommendationAnswerBudget), and the
     * packer reserves exactly that: the window above the profile estimate is 1500 overhead + 1536 bound + 9 for the
     * empty FAVORITES section + 120 tokens left.
     */
    public function testTheBatchReplyReserveIsTheProvidersAnswerBound(): void
    {
        $candidates = array_map(
            static fn (int $id): ArticleLineModel => new ArticleLineModel($id, 'T', 'F', 'D', null),
            range(100, 129),
        );

        $batches = $this->builder->packBatches(
            $candidates,
            $this->emptyHistory(),
            $this->settings(RecommendationPromptBuilder::ESTIMATED_PROFILE_TOKENS + 3165, 1, maximumBatchSize: 50),
        );

        self::assertSame([20, 10], array_map('count', $batches));
    }

    public function testDistillMessagesCarryAllThreeHistorySections(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav one', 10), self::line(2, 'Fav two', 10)],
            kept: [self::line(3, 'Kept one', 10), self::line(4, 'Kept two', 10)],
            viewed: [self::line(5, 'Viewed one', 10), self::line(6, 'Viewed two', 10)],
        );

        $messages = $this->builder->distillMessages(new ProfileInputsModel($history, []));

        self::assertStringContainsString('FAVORITES', $messages[1]['content']);
        self::assertStringContainsString('KEPT', $messages[1]['content']);
        self::assertStringContainsString('VIEWED', $messages[1]['content']);
        self::assertStringContainsString('"profile"', $messages[0]['content']);
    }

    public function testDistillMessagesListTheSavedSearchesAheadOfTheHistory(): void
    {
        $inputs = new ProfileInputsModel(
            new RecommendationHistoryModel(favorites: [self::line(1, 'Fav one', 10)], kept: [], viewed: []),
            ['rust', '"home assistant"'],
        );

        $user = $this->builder->distillMessages($inputs)[1]['content'];

        self::assertStringStartsWith(
            "SAVED SEARCHES:\n- rust\n- \"home assistant\"\n\nFAVORITES (newest first):\n- Fav one",
            $user,
        );
    }

    public function testTheDistillRoleWeighsSavedSearchesWithFavourites(): void
    {
        self::assertStringContainsString(
            'SAVED SEARCHES and FAVORITES weigh strongest, KEPT next, VIEWED least',
            RecommendationPromptText::DISTILL_ROLE,
        );
    }

    public function testTheDistillRoleStatesTheProfileWordCap(): void
    {
        self::assertStringContainsString(
            'at most about ' . RecommendationPromptText::PROFILE_WORD_CAP . ' words',
            RecommendationPromptText::DISTILL_ROLE,
        );
    }

    public function testDistillMessagesReturnsTheExactRoleContentStructure(): void
    {
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->distillMessages(new ProfileInputsModel($history, []));

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

    /** The shortlist is rendered candidate-style, so each line carries its id. */
    public function testConsolidationMessagesCarryProfileFavouritesAndShortlist(): void
    {
        $pool = [['id' => 5, 'score' => 900, 'reason' => '']];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)];
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->consolidationMessages(
            new PromptContext($history, $this->settings(32768, 100), 'Likes Rust.'),
            $pool,
            $lines,
        );

        // Anchored to the header, not merely both present: the text must follow "PROFILE:\n".
        self::assertStringContainsString("PROFILE:\nLikes Rust.", $messages[1]['content']);
        self::assertStringContainsString('FAVORITES', $messages[1]['content']);
        self::assertStringNotContainsString('KEPT', $messages[1]['content']);
        self::assertStringNotContainsString('VIEWED', $messages[1]['content']);
        self::assertStringContainsString('[5]', $messages[1]['content']);
        self::assertStringContainsString('Rust 2.0 released', $messages[1]['content']);
        self::assertStringContainsString('duplicates', $messages[0]['content']);
        // CONSOLIDATION_ROLE mentions "duplicates" too, so this pins the output contract's own sentence.
        self::assertStringContainsString('Reply with JSON only, no prose', $messages[0]['content']);
        // The 0-1000 calibration must survive: without the explicit bands, the three-digit anchor and the anti-0-100
        // guard the local model scores on 0-100, and every score is stored at a tenth of its value.
        self::assertStringContainsString('900-1000', $messages[0]['content']);
        self::assertStringContainsString('do not score on a 0-100 scale', $messages[0]['content']);
        self::assertStringContainsString('Score every candidate line, never leave one out', $messages[0]['content']);
    }

    public function testConsolidationMessagesReturnsTheExactRoleContentStructure(): void
    {
        $pool = [['id' => 5, 'score' => 900, 'reason' => '']];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)];
        $history = new RecommendationHistoryModel(
            favorites: [self::line(1, 'Fav one', 10)],
            kept: [self::line(2, 'Kept one', 10)],
            viewed: [self::line(3, 'Viewed one', 10)],
        );

        $messages = $this->builder->consolidationMessages(
            new PromptContext($history, $this->settings(32768, 100), 'Likes Rust.'),
            $pool,
            $lines,
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

    /** A pruned entry (absent from $linesById) is dropped, not carried as a null: candidateLine() would fatal on it. */
    public function testConsolidationMessagesDropsAPrunedPoolEntryFromTheShortlist(): void
    {
        $pool = [
            ['id' => 5, 'score' => 900, 'reason' => ''],
            ['id' => 6, 'score' => 500, 'reason' => ''],
        ];
        $lines = [5 => self::line(5, 'Rust 2.0 released', 10)]; // 6 pruned since its batch ran

        $messages = $this->builder->consolidationMessages(
            $this->defaultContext(),
            $pool,
            $lines,
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

        $this->builder->consolidationMessages(
            $this->defaultContext(),
            [],
            [],
        );
    }

    private static function line(int $id, string $title, int $descriptionChars): ArticleLineModel
    {
        return new ArticleLineModel(
            entryId: $id,
            title: $title,
            feedName: 'Example Feed',
            date: '2026-08-01',
            description: str_repeat('x', $descriptionChars),
        );
    }

    private function emptyHistory(): RecommendationHistoryModel
    {
        return new RecommendationHistoryModel(favorites: [], kept: [], viewed: []);
    }

    /** A generous window, no profile, and no history — the baseline context tests reach for by default. */
    private function defaultContext(): PromptContext
    {
        return new PromptContext($this->emptyHistory(), $this->settings(32768, 100), null);
    }

    private function settings(
        int $contextWindow,
        int $picksLimit,
        ?string $guidancePrompt = null,
        RecommendationBatchSize $batchSize = RecommendationBatchSize::Medium,
        int $maximumBatchSize = RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
    ): EffectiveRecommendationSettingsModel {
        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: $guidancePrompt,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: new RecommendationPoolLimits(500, RecommendationSettings::DEFAULT_LOOKBACK_DAYS, $picksLimit),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: $contextWindow,
                contextWindowSource: 'default',
                batchSize: $batchSize,
                maximumBatchSize: $maximumBatchSize,
            ),
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            showScoreAndReasons: false,
        );
    }
}
