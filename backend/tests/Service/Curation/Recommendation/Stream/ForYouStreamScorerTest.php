<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Stream\ForYouStreamScorer;
use App\Service\Curation\Recommendation\Stream\Model\EntryStemsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use PHPUnit\Framework\TestCase;

final class ForYouStreamScorerTest extends TestCase
{
    private ForYouStreamScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ForYouStreamScorer();
    }

    public function testTheScoreSumsStrengthTimesPositionOverMatchedStems(): void
    {
        $terms = InterestTermsModel::fromStrengths(['en' => ['solar' => 2.0, 'grid' => 1.0]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0, 'grid' => 0.5, 'other' => 1.0]);

        $score = $this->scorer->findMatch($terms, $stems);

        self::assertEqualsWithDelta(2.5, $score?->score, 1e-9);
        self::assertSame(['solar', 'grid'], $score?->matchedStems);
    }

    public function testTheStrongestContributionComesFirst(): void
    {
        $terms = InterestTermsModel::fromStrengths(['en' => ['grid' => 3.0, 'solar' => 2.0]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0, 'grid' => 1.0]);

        self::assertSame(['grid', 'solar'], $this->scorer->findMatch($terms, $stems)?->matchedStems);
    }

    public function testAScoreBelowTheThresholdIsNoMatch(): void
    {
        $terms = InterestTermsModel::fromStrengths(['en' => ['solar' => 1.49]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0]);

        self::assertNull($this->scorer->findMatch($terms, $stems));
    }

    public function testAScoreAtTheThresholdIsAMatch(): void
    {
        $terms = InterestTermsModel::fromStrengths(['en' => ['solar' => 1.5]]);
        $stems = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0]);

        self::assertEqualsWithDelta(1.5, $this->scorer->findMatch($terms, $stems)?->score, 1e-9);
    }

    public function testStemsAreLookedUpInTheEntrysLanguageOnly(): void
    {
        $terms = InterestTermsModel::fromStrengths(['de' => ['solar' => 5.0]]);

        $english = new EntryStemsModel(FeedLanguage::English, ['solar' => 1.0]);
        $german = new EntryStemsModel(FeedLanguage::German, ['solar' => 1.0]);

        self::assertNull($this->scorer->findMatch($terms, $english));
        self::assertNotNull($this->scorer->findMatch($terms, $german));
    }

    public function testAnEntryWithoutALanguageIsScoredAgainstTheCutForm(): void
    {
        $terms = InterestTermsModel::fromStrengths(['' => ['sola' => 2.0]]);

        self::assertNotNull($this->scorer->findMatch($terms, new EntryStemsModel(null, ['sola' => 1.0])));
    }
}
