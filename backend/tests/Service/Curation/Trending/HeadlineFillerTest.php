<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\Support\HeadlineStems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadlineFillerTest extends TestCase
{
    private HeadlineFiller $filler;

    protected function setUp(): void
    {
        $this->filler = new HeadlineFiller();
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
        self::assertTrue($this->filler->isSeed('the'));
        self::assertTrue($this->filler->isSeed('fur'));
        self::assertTrue($this->filler->isSeed('uber'));
        self::assertTrue($this->filler->isSeed('becaus'));
        self::assertFalse($this->filler->isSeed('war'));
        self::assertFalse($this->filler->isSeed('storm'));
    }

    public function testRarityFollowsTheLogOfTheFlooredCountOverTheDocumentFrequency(): void
    {
        self::assertEqualsWithDelta(6.6214, $this->filler->rarityWeight(1, 1_000), 0.0001);
        self::assertEqualsWithDelta(5.7044, $this->filler->rarityWeight(9, 3_000), 0.0001);
    }

    #[DataProvider('frenchAndSpanishFunctionWords')]
    public function testFrenchAndSpanishFunctionWordsAreSeeds(string $stem): void
    {
        self::assertTrue($this->filler->isSeed($stem));
    }

    /** @return iterable<string, array{string}> */
    public static function frenchAndSpanishFunctionWords(): iterable
    {
        $words = 'les pour que dans une sur avec est pas qui aux par plus del los las para una por con como mas sus entre';
        foreach (explode(' ', $words) as $word) {
            yield $word => [$word];
        }
    }

    public function testAWordThatCarriesMeaningInAnotherLanguageIsNotASeed(): void
    {
        foreach (['son', 'sin', 'fin'] as $word) {
            self::assertFalse($this->filler->isSeed($word), $word);
        }
    }

    public function testEverySeedIsInStemForm(): void
    {
        $seeds = new \ReflectionClassConstant(HeadlineFiller::class, 'SEED_STEMS')->getValue();
        self::assertIsArray($seeds);
        foreach (array_keys($seeds) as $seed) {
            self::assertSame([(string) $seed], HeadlineStems::ofWords(HeadlineStems::words((string) $seed)));
        }
    }
}
