<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Tests\Support\LanguageWiring;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\TestCase;

final class SearchTermStemsTest extends TestCase
{
    private SearchTermStems $stems;

    protected function setUp(): void
    {
        $this->stems = new SearchTermStems(
            StemmingWiring::equivalents(),
            StemmingWiring::stopWordStems(),
            LanguageWiring::stopWords(),
        );
    }

    public function testACompoundSearchTermStemsItsPartsAtHalfWeight(): void
    {
        [[$word, $bare]] = StemmingWiring::equivalents()->canonicalizeWords('Wärmepumpe');
        [$whole, $warm, $pump] = StemmingWiring::equivalents()->stemWordAndParts($word, $bare, FeedLanguage::German);

        self::assertSame(
            [$whole => 1.0, $warm => 0.5, $pump => 0.5],
            $this->collectStems('Wärmepumpe', FeedLanguage::German),
        );
    }

    public function testASearchOfOnlyOneLanguagesStopWordsAddsNoStemInAnyLanguage(): void
    {
        foreach ([...FeedLanguage::cases(), null] as $language) {
            self::assertSame([], $this->collectStems('der die das', $language), $language->value ?? 'cut');
        }
    }

    public function testAWordIsDroppedOnlyWhereItIsAStopWord(): void
    {
        $english = $this->collectStems('Ukraine war', FeedLanguage::English);
        $german = $this->collectStems('Ukraine war', FeedLanguage::German);

        self::assertArrayHasKey(StemmingWiring::stemmer()->stem('war', FeedLanguage::English), $english);
        self::assertArrayNotHasKey(StemmingWiring::stemmer()->stem('war', FeedLanguage::German), $german);
        self::assertArrayHasKey(StemmingWiring::stemmer()->stem('ukraine', FeedLanguage::German), $german);
    }

    public function testAStopWordBesideAContentWordStaysWhereItIsNoStopWord(): void
    {
        $english = $this->collectStems('Die Hard', FeedLanguage::English);

        self::assertArrayHasKey(StemmingWiring::stemmer()->stem('die', FeedLanguage::English), $english);
        self::assertArrayNotHasKey('die', $this->collectStems('Die Hard', FeedLanguage::German));
    }

    public function testAWordWhoseStemMatchesAStopWordStemIsNoStopWordSearch(): void
    {
        self::assertSame(
            [StemmingWiring::stemmer()->stem('solar', FeedLanguage::English) => 1.0],
            $this->collectStems('solar', FeedLanguage::English),
        );
    }

    public function testContentWordsKeepTheirStemsInEveryLanguage(): void
    {
        foreach ([...FeedLanguage::cases(), null] as $language) {
            $stems = $this->collectStems('Wärmepumpe Förderung', $language);

            self::assertCount($language === FeedLanguage::German ? 4 : 2, $stems, $language->value ?? 'cut');
        }
    }

    public function testStopWordsAndOverlongStemsAreDropped(): void
    {
        self::assertSame([], $this->collectStems('the ' . str_repeat('a', 70), FeedLanguage::English));
    }

    public function testAStemAtTheLengthLimitIsKept(): void
    {
        $fits = str_repeat('q', YourStreamWord::STEM_MAX_LENGTH);

        self::assertSame([$fits => 1.0], $this->collectStems($fits, FeedLanguage::English));
    }

    public function testAWholeWordOutweighsTheSameStemAsAPartInEitherOrder(): void
    {
        $pump = StemmingWiring::stemmer()->stem('pumpe', FeedLanguage::German);

        self::assertSame(1.0, $this->collectStems('Pumpe Wärmepumpe', FeedLanguage::German)[$pump]);
        self::assertSame(1.0, $this->collectStems('Wärmepumpe Pumpe', FeedLanguage::German)[$pump]);
    }

    public function testTheCutFormStemsEveryWord(): void
    {
        self::assertSame(
            [WordStems::cut('climate') => 1.0, WordStems::cut('change') => 1.0],
            $this->collectStems('climate change', null),
        );
    }

    public function testATermIsOneWordWhenItKeepsOneWord(): void
    {
        self::assertTrue($this->stems->buildTerm('Solar')->isSingleWord);
        self::assertFalse($this->stems->buildTerm('solar power')->isSingleWord);
        self::assertTrue($this->stems->buildTerm('the')->isSingleWord);
    }

    /** @return array<string, float> */
    private function collectStems(string $term, ?FeedLanguage $language): array
    {
        return $this->stems->collectStems($this->stems->buildTerm($term), $language);
    }
}
