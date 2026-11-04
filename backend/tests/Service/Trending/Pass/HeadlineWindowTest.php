<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Pass;

use App\Service\Trending\HeadlineFiller;
use App\Service\Trending\Pass\HeadlineWindow;
use PHPUnit\Framework\TestCase;

final class HeadlineWindowTest extends TestCase
{
    private const float ONCE = 6.6214;
    private const float TWICE = 6.2159;

    private HeadlineWindow $window;

    protected function setUp(): void
    {
        $this->window = new HeadlineWindow(new HeadlineFiller());
    }

    public function testSeedWordsAreNeverWeighed(): void
    {
        $this->window->add(1, 10, 'The storm and the flood', '');

        self::assertEqualsWithDelta(2 * self::ONCE, $this->window->totalWeight(1), 0.0001);
    }

    public function testAHeadlineStemWeighsOneABodyStemHalfAndAStemInBothCountsOnceAtOne(): void
    {
        $this->window->add(1, 10, 'Storm floods', 'Storm warning');

        self::assertEqualsWithDelta(2.5 * self::ONCE, $this->window->totalWeight(1), 0.0001);
    }

    public function testEntriesShareTheLowerPlaceWeightOfEachCommonStem(): void
    {
        $this->window->add(1, 10, 'Storm Hamburg', '');
        $this->window->add(2, 20, 'Hamburg harbour', 'Storm surge');

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(1.5 * self::TWICE, $shared[1], 0.0001);
        self::assertSame([], $this->window->sharedWeightsWithEarlier(1));
    }

    public function testEntriesSharingOnlyBodyStemsAreNotPaired(): void
    {
        $this->window->add(1, 10, 'Storm Hamburg', '');
        $this->window->add(2, 20, 'Ferry cancelled', 'Storm Hamburg');

        self::assertSame([], $this->window->sharedWeightsWithEarlier(2));
    }

    public function testAStemInThreePercentOfTheFlooredWindowIsFiller(): void
    {
        $this->addCommonHeadlines(45);

        self::assertEqualsWithDelta(
            log(1502 / 2),
            $this->window->totalWeight(1),
            0.0001,
            'the common stem is in 45 of a window counted as 1 500, so only the unique stem weighs',
        );
    }

    public function testAStemInFewerThanThreePercentStillWeighs(): void
    {
        $this->addCommonHeadlines(44);

        self::assertEqualsWithDelta(log(1502 / 2) + log(1502 / 45), $this->window->totalWeight(1), 0.0001);
    }

    public function testAStemInHalfOfOneFeedsEntriesIsIgnoredForThatFeedOnly(): void
    {
        $this->window->add(1, 7, 'Fleischhauer: Koalition', '');
        $this->window->add(2, 7, 'Fleischhauer: Haushalt', '');
        $this->window->add(3, 7, 'Bahnstreik', '');
        $this->window->add(4, 7, 'Wetter', '');
        $this->window->add(5, 8, 'Fleischhauer kommentiert Koalition', '');

        $shared = $this->window->sharedWeightsWithEarlier(5);

        self::assertSame([1], array_keys($shared), 'entry 2 shares only the feed-7 filler stem');
        self::assertEqualsWithDelta(self::TWICE, $shared[1], 0.0001, 'koalit alone: fleisc is filler for feed 7');
        self::assertEqualsWithDelta(
            log(1502 / 4) + self::ONCE + self::TWICE,
            $this->window->totalWeight(5),
            0.0001,
            'feed 8 still weighs fleisc (df 3), kommen (df 1) and koalit (df 2)',
        );
    }

    public function testAFeedFillerStemInTheBodyIsIgnoredToo(): void
    {
        $this->window->add(1, 7, 'Koalition', 'Fleischhauer');
        $this->window->add(2, 7, 'Haushalt', 'Fleischhauer');
        $this->window->add(3, 7, 'Bahnstreik', '');
        $this->window->add(4, 7, 'Wetter', '');
        $this->window->add(5, 8, 'Fleischhauer kommentiert Koalition', '');

        self::assertEqualsWithDelta(self::TWICE, $this->window->totalWeight(1), 0.0001, 'koalit alone');
        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(5)[1], 0.0001);
    }

    public function testAFeedWithThreeEntriesKeepsAStemTheyAllHold(): void
    {
        $this->window->add(1, 7, 'Fleischhauer: Koalition', '');
        $this->window->add(2, 7, 'Fleischhauer: Haushalt', '');
        $this->window->add(3, 7, 'Fleischhauer: Bahn', '');
        $this->window->add(4, 8, 'Fleischhauer kommentiert', '');

        self::assertSame([1, 2, 3], array_keys($this->window->sharedWeightsWithEarlier(4)));
    }

    public function testTheEntryCountCountsEveryAddedEntry(): void
    {
        $this->window->add(1, 7, 'One', '');
        $this->window->add(2, 8, 'Two', '');

        self::assertSame(2, $this->window->entryCount());
    }

    private function addCommonHeadlines(int $holders): void
    {
        for ($index = 1; $index <= $holders; $index++) {
            $this->window->add($index, $index, 'Gemeinsam ' . self::uniqueWord($index), '');
        }
        $this->window->add($holders + 1, $holders + 1, self::uniqueWord($holders + 1), '');
    }

    private static function uniqueWord(int $index): string
    {
        return 'zeta' . \chr(97 + intdiv($index, 26)) . \chr(97 + $index % 26);
    }
}
