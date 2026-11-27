<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\TrendingWiring;
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

    public function testASeedIsAStopWordStem(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertTrue($this->filler->isSeed($stemmer->stem('con', FeedLanguage::Spanish), FeedLanguage::Spanish));
        self::assertFalse($this->filler->isSeed('hausautomatisierung', null));
    }
}
