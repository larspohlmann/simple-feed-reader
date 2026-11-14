<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\Support\HeadlineStems;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadlineFillerTest extends TestCase
{
    private HeadlineFiller $filler;

    protected function setUp(): void
    {
        $this->filler = TrendingWiring::filler();
    }

    public function testAQuietWindowIsCountedAsFifteenHundredEntries(): void
    {
        self::assertTrue($this->filler->isWindowFiller(45, 1_000));
        self::assertFalse($this->filler->isWindowFiller(44, 1_000));
    }

    public function testABusyWindowIsCountedAsItIs(): void
    {
        self::assertTrue($this->filler->isWindowFiller(120, 4_000));
        self::assertFalse($this->filler->isWindowFiller(119, 4_000));
    }

    public function testAStemInHalfOfAFeedsEntriesIsFillerForThatFeed(): void
    {
        self::assertTrue($this->filler->isFeedFiller(2, 4));
        self::assertFalse($this->filler->isFeedFiller(1, 4));
        self::assertTrue($this->filler->isFeedFiller(4, 7));
        self::assertFalse($this->filler->isFeedFiller(3, 7));
    }

    public function testAFeedWithFewerThanFourEntriesHasNoFiller(): void
    {
        self::assertFalse($this->filler->isFeedFiller(3, 3));
    }

    public function testTheSeedListHoldsEnglishAndGermanFunctionWordsAsStems(): void
    {
        self::assertTrue($this->filler->isSeed('the', null));
        self::assertTrue($this->filler->isSeed('fur', null));
        self::assertTrue($this->filler->isSeed('uber', null));
        self::assertTrue($this->filler->isSeed('becaus', null));
        self::assertFalse($this->filler->isSeed('war', null));
        self::assertFalse($this->filler->isSeed('storm', null));
    }

    public function testRarityFollowsTheLogOfTheFlooredCountOverTheDocumentFrequency(): void
    {
        self::assertEqualsWithDelta(6.6214, $this->filler->rarityWeight(1, 1_000), 0.0001);
        self::assertEqualsWithDelta(5.7044, $this->filler->rarityWeight(9, 3_000), 0.0001);
    }

    #[DataProvider('frenchAndSpanishFunctionWords')]
    public function testFrenchAndSpanishFunctionWordsAreSeeds(string $stem): void
    {
        self::assertTrue($this->filler->isSeed($stem, null));
    }

    /** @return iterable<string, array{string}> */
    public static function frenchAndSpanishFunctionWords(): iterable
    {
        $french = 'les pour que dans une sur avec est pas qui aux par fois';
        $spanish = 'del los las para una por mas sus entre';
        foreach (explode(' ', $french . ' ' . $spanish) as $word) {
            yield $word => [$word];
        }
    }

    public function testAWordThatCarriesMeaningInAnotherLanguageIsNotASeed(): void
    {
        $meaningful = ['son', 'sin', 'fin', 'plus', 'con', 'han', 'sans'];
        $names = ['contra', 'contre', 'durant', 'esa', 'ces', 'como'];
        foreach ([...$meaningful, ...$names] as $word) {
            self::assertFalse($this->filler->isSeed($word, null), $word);
        }
    }

    public function testEverySeedWithoutALanguageIsInCutForm(): void
    {
        $seeds = new \ReflectionClassConstant(HeadlineFiller::class, 'SEED_STEMS')->getValue();
        self::assertIsArray($seeds);
        foreach (array_keys($seeds) as $seed) {
            self::assertSame((string) $seed, HeadlineStems::cut((string) $seed));
            self::assertTrue(HeadlineStems::isKept((string) $seed), (string) $seed);
        }
    }

    public function testAWordThatCarriesMeaningElsewhereIsASeedInItsOwnLanguageOnly(): void
    {
        $stemmer = TrendingWiring::stemmer();

        self::assertTrue($this->filler->isSeed($stemmer->stem('con', FeedLanguage::Spanish), FeedLanguage::Spanish));
        self::assertFalse($this->filler->isSeed($stemmer->stem('con', FeedLanguage::English), FeedLanguage::English));
        self::assertFalse($this->filler->isSeed('con', null));
    }

    #[DataProvider('seedWordsByLanguage')]
    public function testEachLanguagesFunctionWordsAreSeedsThroughItsStemmer(FeedLanguage $language, string $word): void
    {
        self::assertTrue($this->filler->isSeed(TrendingWiring::stemmer()->stem($word, $language), $language));
    }

    /** @return iterable<string, array{FeedLanguage, string}> */
    public static function seedWordsByLanguage(): iterable
    {
        $words = [
            'de' => 'für über werden diese einen',
            'en' => 'because against between the during',
            'fr' => 'être après déjà plus sans contre durant ces',
            'es' => 'también según más con sin son como esa contra',
        ];
        foreach ($words as $language => $list) {
            foreach (explode(' ', $list) as $word) {
                yield $language . ' ' . $word => [FeedLanguage::from($language), $word];
            }
        }
    }

    #[DataProvider('contentWordsByLanguage')]
    public function testAContentWordIsNoSeedInItsLanguage(FeedLanguage $language, string $word): void
    {
        self::assertFalse($this->filler->isSeed(TrendingWiring::stemmer()->stem($word, $language), $language));
    }

    /** @return iterable<string, array{FeedLanguage, string}> */
    public static function contentWordsByLanguage(): iterable
    {
        yield 'de Sturm' => [FeedLanguage::German, 'sturm'];
        yield 'en con' => [FeedLanguage::English, 'con'];
        yield 'fr guerre' => [FeedLanguage::French, 'guerre'];
        yield 'es guerra' => [FeedLanguage::Spanish, 'guerra'];
    }
}
