<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\InterestTerm;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\TestCase;

final class SearchTermStemsTest extends TestCase
{
    private SearchTermStems $stems;

    protected function setUp(): void
    {
        $this->stems = new SearchTermStems(StemmingWiring::equivalents(), StemmingWiring::stopWordStems());
    }

    public function testACompoundSearchTermStemsItsPartsAtHalfWeight(): void
    {
        [[$word, $bare]] = StemmingWiring::equivalents()->canonicalizeWords('Wärmepumpe');
        [$whole, $warm, $pump] = StemmingWiring::equivalents()->stemWordAndParts($word, $bare, FeedLanguage::German);

        self::assertSame(
            [$whole => 1.0, $warm => 0.5, $pump => 0.5],
            $this->stems->collectStems('Wärmepumpe', FeedLanguage::German),
        );
    }

    public function testStopWordsAndOverlongStemsAreDropped(): void
    {
        self::assertSame([], $this->stems->collectStems('the ' . str_repeat('a', 70), FeedLanguage::English));
    }

    public function testAStemAtTheLengthLimitIsKept(): void
    {
        $fits = str_repeat('q', InterestTerm::STEM_MAX_LENGTH);

        self::assertSame([$fits => 1.0], $this->stems->collectStems($fits, FeedLanguage::English));
    }

    public function testAWholeWordOutweighsTheSameStemAsAPartInEitherOrder(): void
    {
        $pump = StemmingWiring::stemmer()->stem('pumpe', FeedLanguage::German);

        self::assertSame(1.0, $this->stems->collectStems('Pumpe Wärmepumpe', FeedLanguage::German)[$pump]);
        self::assertSame(1.0, $this->stems->collectStems('Wärmepumpe Pumpe', FeedLanguage::German)[$pump]);
    }

    public function testTheCutFormStemsEveryWord(): void
    {
        self::assertSame(
            [WordStems::cut('climate') => 1.0, WordStems::cut('change') => 1.0],
            $this->stems->collectStems('climate change', null),
        );
    }
}
