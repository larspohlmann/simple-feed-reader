<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Tests\Support\LanguageWiring;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StopWordStemsTest extends TestCase
{
    private StopWordStems $stopWordStems;

    protected function setUp(): void
    {
        $this->stopWordStems = StemmingWiring::stopWordStems();
    }

    /** @return iterable<string, array{FeedLanguage}> */
    public static function languages(): iterable
    {
        foreach (FeedLanguage::cases() as $language) {
            yield $language->value => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryStopWordIsAStopWordStemThroughItsLanguagesStemmer(FeedLanguage $language): void
    {
        $stemmer = StemmingWiring::stemmer();
        $words = LanguageWiring::stopWords()->listWords($language);

        self::assertNotEmpty($words);
        foreach ($words as $word) {
            self::assertTrue($this->stopWordStems->isStopWordStem($stemmer->stem($word, $language), $language), $word);
        }
    }

    #[DataProvider('languages')]
    public function testEveryKeptStopWordsCutIsAStopWordStemWithoutALanguage(FeedLanguage $language): void
    {
        $keptWords = array_filter(
            LanguageWiring::stopWords()->listWords($language),
            static fn (string $word): bool => WordStems::isKept(WordStems::bare($word)),
        );

        self::assertNotEmpty($keptWords);
        foreach ($keptWords as $word) {
            self::assertTrue($this->stopWordStems->isStopWordStem(WordStems::cut($word), null), $word);
        }
    }

    public function testAStopWordIsAStopWordOnlyInTheLanguageThatListsIt(): void
    {
        self::assertTrue($this->stopWordStems->isStopWord('war', FeedLanguage::German));
        self::assertFalse($this->stopWordStems->isStopWord('war', FeedLanguage::English));
        self::assertTrue($this->stopWordStems->isStopWord('über', FeedLanguage::German));
        self::assertFalse($this->stopWordStems->isStopWord('solar', FeedLanguage::Spanish));
    }

    public function testAWordInNoListIsNoStopWordStemWithoutALanguage(): void
    {
        foreach (['storm', 'guerre', 'sturm'] as $word) {
            self::assertFalse($this->stopWordStems->isStopWordStem($word, null), $word);
        }
    }

    public function testAStopWordStemOfOneLanguageIsNoStopWordStemInAnotherUnlessItsListHasIt(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertTrue($this->stopWordStems->isStopWordStem(
            $stemmer->stem('con', FeedLanguage::Spanish),
            FeedLanguage::Spanish,
        ));
        self::assertFalse($this->stopWordStems->isStopWordStem(
            $stemmer->stem('con', FeedLanguage::English),
            FeedLanguage::English,
        ));
    }

    #[DataProvider('contentWordsByLanguage')]
    public function testAContentWordIsNoStopWordStemInItsLanguage(FeedLanguage $language, string $word): void
    {
        self::assertFalse($this->stopWordStems->isStopWordStem(
            StemmingWiring::stemmer()->stem($word, $language),
            $language,
        ));
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
