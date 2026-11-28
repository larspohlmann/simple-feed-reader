<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
use App\Service\Curation\YourStream\YourStreamScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YourStreamScorerTest extends TestCase
{
    private const float THRESHOLD = 1.5;

    private YourStreamScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new YourStreamScorer();
    }

    public function testTheScoreSumsStrengthTimesPositionOverMatchedStems(): void
    {
        $terms = StreamStrengthsModel::fromStrengths(['en' => ['solar' => 2.0, 'grid' => 1.0]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0, 'grid' => 0.5, 'other' => 1.0]);

        $score = $this->scorer->findMatch($terms, $stems, self::THRESHOLD);

        self::assertEqualsWithDelta(2.5, $score?->score, 1e-9);
        self::assertSame(['solar', 'grid'], $score?->matchedStems);
    }

    public function testTheStrongestContributionComesFirst(): void
    {
        $terms = StreamStrengthsModel::fromStrengths(['en' => ['grid' => 3.0, 'solar' => 2.0]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0, 'grid' => 1.0]);

        self::assertSame(['grid', 'solar'], $this->scorer->findMatch($terms, $stems, self::THRESHOLD)?->matchedStems);
    }

    public function testANumericStemComesBackAsAString(): void
    {
        $terms = StreamStrengthsModel::fromStrengths(['en' => ['2026' => 2.0]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['2026' => 1.0]);

        self::assertSame(['2026'], $this->scorer->findMatch($terms, $stems, self::THRESHOLD)?->matchedStems);
    }

    #[DataProvider('providePrecisions')]
    public function testAScoreOnTheThresholdMatchesAndJustBelowDoesNot(YourStreamPrecision $precision): void
    {
        $minimumScore = $precision->minimumScore();
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0]);
        $onTheThreshold = StreamStrengthsModel::fromStrengths(['en' => ['solar' => $minimumScore]]);
        $justBelow = StreamStrengthsModel::fromStrengths(['en' => ['solar' => $minimumScore - 0.01]]);

        $match = $this->scorer->findMatch($onTheThreshold, $stems, $minimumScore);

        self::assertEqualsWithDelta($minimumScore, $match?->score, 1e-9);
        self::assertNull($this->scorer->findMatch($justBelow, $stems, $minimumScore));
    }

    /** @return iterable<string, array{YourStreamPrecision}> */
    public static function providePrecisions(): iterable
    {
        foreach (YourStreamPrecision::cases() as $precision) {
            yield $precision->value => [$precision];
        }
    }

    public function testStemsAreLookedUpInTheEntrysLanguageOnly(): void
    {
        $terms = StreamStrengthsModel::fromStrengths(['de' => ['solar' => 5.0]]);

        $english = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0]);
        $german = new EntryStemsModel(FeedLanguage::German, ['solar' => 1.0]);

        self::assertNull($this->scorer->findMatch($terms, $english, self::THRESHOLD));
        self::assertNotNull($this->scorer->findMatch($terms, $german, self::THRESHOLD));
    }

    public function testAnEntryWithoutALanguageIsScoredAgainstTheCutForm(): void
    {
        $terms = StreamStrengthsModel::fromStrengths(['' => ['sola' => 2.0]]);

        $stems = new EntryStemsModel(null, ['sola' => 1.0]);

        self::assertNotNull($this->scorer->findMatch($terms, $stems, self::THRESHOLD));
    }
}
