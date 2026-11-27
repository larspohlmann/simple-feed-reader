<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Tests\Support\LanguageWiring;
use App\Tests\Support\StemmingWiring;
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

    public function testRarityFollowsTheLogOfTheFlooredCountOverTheDocumentFrequency(): void
    {
        self::assertEqualsWithDelta(6.6214, $this->filler->rarityWeight(1, 1_000), 0.0001);
        self::assertEqualsWithDelta(5.7044, $this->filler->rarityWeight(9, 3_000), 0.0001);
    }

    /** @return iterable<string, array{FeedLanguage}> */
    public static function languages(): iterable
    {
        foreach (FeedLanguage::cases() as $language) {
            yield $language->value => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryStopWordIsASeedThroughItsLanguagesStemmer(FeedLanguage $language): void
    {
        $stemmer = StemmingWiring::stemmer();
        $words = LanguageWiring::stopWords()->listWords($language);

        self::assertNotEmpty($words);
        foreach ($words as $word) {
            self::assertTrue($this->filler->isSeed($stemmer->stem($word, $language), $language), $word);
        }
    }

    #[DataProvider('languages')]
    public function testEveryKeptStopWordsCutIsASeedWithoutALanguage(FeedLanguage $language): void
    {
        $keptWords = array_filter(
            LanguageWiring::stopWords()->listWords($language),
            static fn (string $word): bool => WordStems::isKept(WordStems::bare($word)),
        );

        self::assertNotEmpty($keptWords);
        foreach ($keptWords as $word) {
            self::assertTrue($this->filler->isSeed(WordStems::cut($word), null), $word);
        }
    }

    public function testAWordInNoListIsNoSeedWithoutALanguage(): void
    {
        foreach (['storm', 'guerre', 'sturm'] as $word) {
            self::assertFalse($this->filler->isSeed($word, null), $word);
        }
    }

    public function testASeedOfOneLanguageIsNoSeedInAnotherUnlessItsListHasIt(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertTrue($this->filler->isSeed($stemmer->stem('con', FeedLanguage::Spanish), FeedLanguage::Spanish));
        self::assertFalse($this->filler->isSeed($stemmer->stem('con', FeedLanguage::English), FeedLanguage::English));
    }

    #[DataProvider('contentWordsByLanguage')]
    public function testAContentWordIsNoSeedInItsLanguage(FeedLanguage $language, string $word): void
    {
        self::assertFalse($this->filler->isSeed(StemmingWiring::stemmer()->stem($word, $language), $language));
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
