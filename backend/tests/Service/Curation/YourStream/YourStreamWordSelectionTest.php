<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Pass\InterestTally;
use App\Service\Curation\YourStream\Pass\RarityTally;
use App\Service\Curation\YourStream\YourStreamWordSelection;
use PHPUnit\Framework\TestCase;

final class YourStreamWordSelectionTest extends TestCase
{
    private InterestTally $interests;
    private YourStreamWordSelection $selection;

    protected function setUp(): void
    {
        $this->interests = new InterestTally();
        $this->selection = new YourStreamWordSelection();
    }

    public function testAStemInOneInterestArticleIsNotKept(): void
    {
        $this->articles(FeedLanguage::English, 1, ['solar' => 1.0]);
        $this->articles(FeedLanguage::English, 9, []);

        self::assertNotContains('solar', $this->stems($this->pickWords(FeedLanguage::English, 600, [])));
    }

    public function testAStemInTwoInterestArticlesIsKept(): void
    {
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0]);
        $this->articles(FeedLanguage::English, 8, []);

        self::assertSame(['solar'], $this->stems($this->pickWords(FeedLanguage::English, 600, [])));
    }

    public function testAStemThatStandsOutIsKeptAtTheTopWeight(): void
    {
        $this->articles(FeedLanguage::German, 5, ['bundesliga' => 1.0], ['bundesliga' => ['Bundesliga']]);
        $this->articles(FeedLanguage::German, 2, ['spieltag' => 0.5]);
        $this->articles(FeedLanguage::German, 3, []);

        $words = $this->pickWords(FeedLanguage::German, 600, ['bundesliga' => 2]);

        self::assertSame('bundesliga', $words[0]->stem);
        self::assertSame('Bundesliga', $words[0]->word);
        self::assertSame(1.0, $words[0]->weight);
        self::assertLessThan(1.0, $words[1]->weight);
    }

    public function testAStemCommonInTheBackgroundIsNotKept(): void
    {
        $this->articles(FeedLanguage::English, 2, ['report' => 1.0]);

        // p_int = 2.5 / 3, p_bg = 300.5 / 601: a lift of 1.67, under 2.0.
        self::assertSame([], $this->pickWords(FeedLanguage::English, 600, ['report' => 300]));
    }

    public function testAGenericSearchWordThatIsCommonInTheBackgroundIsNotKept(): void
    {
        $this->articles(FeedLanguage::English, 1, ['chang' => 1.0]);
        $this->articles(FeedLanguage::English, 9, []);
        $this->interests->recordSearchTerm(
            FeedLanguage::English,
            ['climat' => 1.0, 'chang' => 1.0],
            'climate change',
        );

        // chang: p_int = 4.5 / 14 against p_bg = 250.5 / 601.
        $words = $this->pickWords(FeedLanguage::English, 600, ['climat' => 2, 'chang' => 250]);

        self::assertSame(['climat'], $this->stems($words));
        self::assertSame('climate change', $words[0]->word);
    }

    public function testASearchTermAloneFillsAListWithoutInterestArticles(): void
    {
        $this->interests->recordSearchTerm(
            FeedLanguage::German,
            ['warmepump' => 1.0, 'warm' => 0.5, 'pump' => 0.5],
            'Wärmepumpe',
        );

        $words = $this->pickWords(FeedLanguage::German, 600, []);

        self::assertSame('warmepump', $words[0]->stem);
        self::assertSame('Wärmepumpe', $words[0]->word);
        self::assertSame(1.0, $words[0]->weight);
    }

    public function testASearchWithoutStemsInALanguageAddsNoArticlesThere(): void
    {
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0]);
        $this->interests->recordSearchTerm(FeedLanguage::English, [], 'the');

        // p_int = 2.5 / 3 keeps solar against p_bg = 180.5 / 601; three more articles would make it 2.5 / 6.
        self::assertSame(['solar'], $this->stems($this->pickWords(FeedLanguage::English, 600, ['solar' => 180])));
    }

    public function testTheFirstSearchNamesAStemTheyShare(): void
    {
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0], ['solar' => ['Solar']]);
        $this->interests->recordSearchTerm(FeedLanguage::English, ['solar' => 1.0], 'solar power');
        $this->interests->recordSearchTerm(FeedLanguage::English, ['solar' => 1.0], 'solar panels');

        self::assertSame('solar power', $this->pickWords(FeedLanguage::English, 600, [])[0]->word);
    }

    public function testAStemShowsItsMostFrequentSurfaceAndTheFirstSeenOnATie(): void
    {
        $this->articles(FeedLanguage::English, 1, ['solar' => 1.0], ['solar' => ['SOLAR']]);
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0], ['solar' => ['Solar']]);
        $this->articles(FeedLanguage::English, 2, ['wind' => 1.0], ['wind' => ['Wind']]);
        $this->articles(FeedLanguage::English, 2, ['wind' => 1.0], ['wind' => ['WIND']]);

        $words = $this->pickWords(FeedLanguage::English, 600, []);

        $wordsByStem = array_map(static fn (StreamWordModel $word): array => [$word->stem, $word->word], $words);
        self::assertSame(['wind' => 'Wind', 'solar' => 'Solar'], array_column($wordsByStem, 1, 0));
    }

    public function testOnATieTheShorterSurfaceWins(): void
    {
        $this->articles(FeedLanguage::English, 1, ['eunion' => 1.0], ['eunion' => ['European Union']]);
        $this->articles(FeedLanguage::English, 1, ['eunion' => 1.0], ['eunion' => ['EU']]);

        self::assertSame('EU', $this->pickWords(FeedLanguage::English, 600, [])[0]->word);
    }

    public function testAStemWithoutASurfaceShowsTheStem(): void
    {
        $this->articles(FeedLanguage::English, 2, ['eunion' => 1.0]);

        self::assertSame('eunion', $this->pickWords(FeedLanguage::English, 600, [])[0]->word);
    }

    public function testAStemTooLongToStoreIsNotKept(): void
    {
        $tooLong = str_repeat('q', YourStreamWord::STEM_MAX_LENGTH + 1);
        $fits = str_repeat('q', YourStreamWord::STEM_MAX_LENGTH);
        $this->articles(FeedLanguage::English, 2, [$tooLong => 1.0, $fits => 1.0]);

        self::assertSame([$fits], $this->stems($this->pickWords(FeedLanguage::English, 600, [])));
    }

    public function testEachLanguageKeepsAtMostSixtyWordsWithWeightsFromOneTenthToOne(): void
    {
        for ($article = 0; $article < 80; $article++) {
            $weights = [];
            for ($stem = 0; $stem < 70; $stem++) {
                if ($article < $stem + 2) {
                    $weights['stem' . $stem] = 1.0;
                }
            }
            $this->interests->recordArticle(new EntryStemsModel(FeedLanguage::English, $weights), []);
        }

        $words = $this->pickWords(FeedLanguage::English, 0, []);

        self::assertCount(YourStreamWordSelection::WORDS_PER_LANGUAGE, $words);
        self::assertSame('stem69', $words[0]->stem);
        self::assertNotContains('stem9', $this->stems($words));
        self::assertSame(1.0, $words[0]->weight);
        self::assertGreaterThanOrEqual(0.1, $words[59]->weight);
        foreach ($words as $index => $word) {
            self::assertEqualsWithDelta(round($word->weight * 10), $word->weight * 10, 1e-9);
            self::assertGreaterThanOrEqual($word->weight, $words[max(0, $index - 1)]->weight);
        }
    }

    public function testAFaintWordStillWeighsOneTenth(): void
    {
        $this->articles(FeedLanguage::English, 2, ['faint' => 0.5, 'top' => 1.0]);
        $this->articles(FeedLanguage::English, 98, ['top' => 1.0]);

        $words = $this->pickWords(FeedLanguage::English, 600, []);

        self::assertSame(['top', 'faint'], $this->stems($words));
        self::assertSame(0.1, $words[1]->weight);
    }

    public function testEqualScoresAreOrderedByStem(): void
    {
        $this->articles(FeedLanguage::English, 2, ['wind' => 1.0, 'solar' => 1.0]);

        self::assertSame(['solar', 'wind'], $this->stems($this->pickWords(FeedLanguage::English, 600, [])));
    }

    public function testRarityFollowsTheBackgroundDocumentFrequency(): void
    {
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0]);

        self::assertEqualsWithDelta(
            log(1002 / 1),
            $this->pickWords(FeedLanguage::English, 1000, [])[0]->rarity,
            1e-9,
        );
        self::assertEqualsWithDelta(log(502 / 1), $this->pickWords(FeedLanguage::English, 100, [])[0]->rarity, 1e-9);
        self::assertEqualsWithDelta(
            log(1002 / 4),
            $this->pickWords(FeedLanguage::English, 1000, ['solar' => 3])[0]->rarity,
            1e-9,
        );
    }

    public function testACutFormWordStaysInTheNullLanguage(): void
    {
        $this->articles(null, 3, ['bundes' => 1.0]);
        $this->articles(null, 1, []);

        self::assertEquals(
            [new StreamWordModel(null, 'bundes', 'bundes', 1.0, log(602 / 1))],
            $this->pickWords(null, 600, []),
        );
    }

    public function testCutFormWordsComeAfterTheLanguages(): void
    {
        $this->articles(null, 2, ['bundes' => 1.0]);
        $this->articles(FeedLanguage::English, 2, ['solar' => 1.0]);
        $this->articles(FeedLanguage::German, 2, ['bundesliga' => 1.0]);

        $words = $this->selection->pickWords($this->interests, new RarityTally($this->interests->collectWantedStems()));

        self::assertSame(
            [FeedLanguage::German, FeedLanguage::English, null],
            array_map(static fn (StreamWordModel $word): ?FeedLanguage => $word->language, $words),
        );
    }

    /**
     * @param array<string, float>        $weights
     * @param array<string, list<string>> $stemWords
     */
    private function articles(?FeedLanguage $language, int $count, array $weights, array $stemWords = []): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->interests->recordArticle(new EntryStemsModel($language, $weights), $stemWords);
        }
    }

    /**
     * @param array<string, int> $documentsByStem
     *
     * @return list<StreamWordModel>
     */
    private function pickWords(?FeedLanguage $language, int $backgroundEntries, array $documentsByStem): array
    {
        $background = new RarityTally($this->interests->collectWantedStems());
        for ($index = 0; $index < $backgroundEntries; $index++) {
            $weights = ['filler' => 1.0];
            foreach ($documentsByStem as $stem => $documents) {
                if ($index < $documents) {
                    $weights[$stem] = 1.0;
                }
            }
            $background->count(new EntryStemsModel($language, $weights));
        }

        return $this->selection->pickWords($this->interests, $background);
    }

    /**
     * @param list<StreamWordModel> $words
     *
     * @return list<string>
     */
    private function stems(array $words): array
    {
        return array_map(static fn (StreamWordModel $word): string => $word->stem, $words);
    }
}
