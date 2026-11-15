<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class HeadlineWindowTest extends TestCase
{
    private const float ONCE = 6.6214;
    private const float TWICE = 6.2159;
    private const float THRICE = 5.9282;

    private HeadlineWindow $window;

    protected function setUp(): void
    {
        $this->window = new HeadlineWindow(TrendingWiring::filler(), TrendingWiring::equivalents());
    }

    public function testSeedWordsAreNeverWeighed(): void
    {
        $this->add(1, 10, 'The storm and the flood', '');

        self::assertEqualsWithDelta(2 * self::ONCE, $this->window->totalWeight(1), 0.0001);
    }

    public function testAHeadlineStemWeighsOneABodyStemHalfAndAStemInBothCountsOnceAtOne(): void
    {
        $this->add(1, 10, 'Storm floods', 'Storm warning');

        self::assertEqualsWithDelta(2.5 * self::ONCE, $this->window->totalWeight(1), 0.0001);
    }

    public function testEntriesShareTheLowerPlaceWeightOfEachCommonStem(): void
    {
        $this->add(1, 10, 'Storm Hamburg', '');
        $this->add(2, 20, 'Hamburg harbour', 'Storm surge');

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(1.5 * self::TWICE, $shared[1], 0.0001);
        self::assertSame([], $this->window->sharedWeightsWithEarlier(1));
    }

    public function testEquivalentSpellingsShareWeight(): void
    {
        $this->add(1, 10, 'Zelensky warns', '');
        $this->add(2, 20, 'Selenskyj mahnt', '');

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(self::TWICE, $shared[1], 0.0001);
    }

    public function testAnEquivalentInTheBodyCountsAtBodyWeight(): void
    {
        $this->add(1, 10, 'Vereinte Nationen tagen in Genf', '');
        $this->add(2, 20, 'Genf empfängt Gäste', 'Die UN beraten');

        self::assertEqualsWithDelta(1.5 * self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
    }

    public function testAStemInTheEarlierBodyIsSharedAtHalfWhereverTheLaterEntryHoldsIt(): void
    {
        $this->add(1, 10, 'Storm Hamburg', 'Elbe');
        $this->add(2, 20, 'Hamburg Elbe', '');
        $this->add(3, 30, 'Hamburg harbour', 'Elbe');

        self::assertEqualsWithDelta(1.5 * self::THRICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
        self::assertEqualsWithDelta(1.5 * self::THRICE, $this->window->sharedWeightsWithEarlier(3)[1], 0.0001);
    }

    public function testEntriesSharingOnlyBodyStemsAreNotPaired(): void
    {
        $this->add(1, 10, 'Storm Hamburg', '');
        $this->add(2, 20, 'Ferry cancelled', 'Storm Hamburg');

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
        $this->add(1, 7, 'Fleischhauer: Koalition', '');
        $this->add(2, 7, 'Fleischhauer: Haushalt', '');
        $this->add(3, 7, 'Bahnstreik', '');
        $this->add(4, 7, 'Wetter', '');
        $this->add(5, 8, 'Fleischhauer kommentiert Koalition', '');

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
        $this->add(1, 7, 'Koalition', 'Fleischhauer');
        $this->add(2, 7, 'Haushalt', 'Fleischhauer');
        $this->add(3, 7, 'Bahnstreik', '');
        $this->add(4, 7, 'Wetter', '');
        $this->add(5, 8, 'Fleischhauer kommentiert Koalition', '');

        self::assertEqualsWithDelta(self::TWICE, $this->window->totalWeight(1), 0.0001, 'koalit alone');
        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(5)[1], 0.0001);
    }

    public function testAFeedFillerStemAnywhereInTheHeadlineIsIgnored(): void
    {
        $this->add(1, 7, 'Koalition: Fleischhauer', '');
        $this->add(2, 7, 'Haushalt: Fleischhauer', '');
        $this->add(3, 7, 'Bahnstreik', '');
        $this->add(4, 7, 'Wetter', '');

        self::assertEqualsWithDelta(self::ONCE, $this->window->totalWeight(1), 0.0001, 'koalit alone');
    }

    public function testAFeedWithThreeEntriesKeepsAStemTheyAllHold(): void
    {
        $this->add(1, 7, 'Fleischhauer: Koalition', '');
        $this->add(2, 7, 'Fleischhauer: Haushalt', '');
        $this->add(3, 7, 'Fleischhauer: Bahn', '');
        $this->add(4, 8, 'Fleischhauer kommentiert', '');

        self::assertSame([1, 2, 3], array_keys($this->window->sharedWeightsWithEarlier(4)));
    }

    public function testTheEntryCountCountsEveryAddedEntry(): void
    {
        $this->add(1, 7, 'One', '');
        $this->add(2, 8, 'Two', '');

        self::assertSame(2, $this->window->entryCount());
    }

    public function testACompoundWhoseRemainderTheLexiconLacksStaysWhole(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Bundesregierung beschließt Haushalt');
        $this->addIn(FeedLanguage::German, 2, 20, 'Bundesliga: Bayern gewinnt');

        self::assertSame([], $this->window->sharedWeightsWithEarlier(2));
    }

    public function testACompoundSharesItsPartWithTheBareWord(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizei räumt Camp');
        $this->addIn(FeedLanguage::German, 2, 20, 'Großer Polizeieinsatz am Bahnhof');

        self::assertSame([1], array_keys($this->window->sharedWeightsWithEarlier(2)));
    }

    public function testTheSameCompoundSharesMoreThanOneOfItsParts(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizei räumt Camp');
        $this->addIn(FeedLanguage::German, 2, 20, 'Großer Polizeieinsatz am Bahnhof');
        $this->addIn(FeedLanguage::German, 3, 30, 'Polizeieinsatz am Bahnhof');
        $this->addIn(FeedLanguage::German, 4, 40, 'Polizeieinsatz am Bahnhof');

        self::assertGreaterThan(
            $this->window->sharedWeightsWithEarlier(2)[1],
            $this->window->sharedWeightsWithEarlier(4)[3],
        );
    }

    public function testAPartThatIsAlsoAWordOfTheHeadlineCountsOnce(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizei: Polizeieinsatz');

        self::assertEqualsWithDelta(3 * self::ONCE, $this->window->totalWeight(1), 0.0001);
    }

    public function testAPartStemInThreePercentOfTheFlooredWindowIsFiller(): void
    {
        $this->addTagCompoundHeadlines(45);

        self::assertArrayNotHasKey(1, $this->window->sharedWeightsWithEarlier(2), 'they share only the part tag');
    }

    public function testAPartStemInFewerThanThreePercentLinksTwoCompounds(): void
    {
        $this->addTagCompoundHeadlines(44);

        self::assertArrayHasKey(1, $this->window->sharedWeightsWithEarlier(2), 'they share the part tag');
    }

    public function testANameLinksAnEntryWithoutALanguageToAGermanOne(): void
    {
        $this->add(1, 10, 'Macron besucht Merz', '');
        $this->addIn(FeedLanguage::German, 2, 20, 'Merz empfängt Macron in Berlin');

        self::assertSame([1], array_keys($this->window->sharedWeightsWithEarlier(2)));
    }

    public function testAWordIsStemmedAgainInEachLanguage(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Wahlen');
        $this->addIn(FeedLanguage::English, 2, 20, 'Wahlen');
        $this->addIn(FeedLanguage::German, 3, 30, 'Wahlen');

        self::assertSame([1], array_keys($this->window->sharedWeightsWithEarlier(3)));
    }

    public function testAWordWithoutALanguageIsNotTakenForALanguageCodeAndAWord(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Wahlen');
        $this->add(2, 20, 'Dewahlen', '');

        self::assertSame([], $this->window->sharedWeightsWithEarlier(2));
    }

    public function testSeedsAreCheckedInTheEntrysLanguage(): void
    {
        $this->addIn(FeedLanguage::Spanish, 1, 10, 'Con Lula');

        self::assertEqualsWithDelta(self::ONCE, $this->window->totalWeight(1), 0.0001, 'con is a Spanish seed');
    }

    private function add(int $entryId, int $feedId, string $headline, string $body): void
    {
        $this->window->add(self::entry($entryId, $feedId, null, $headline), $body);
    }

    private function addIn(FeedLanguage $language, int $entryId, int $feedId, string $headline): void
    {
        $this->window->add(self::entry($entryId, $feedId, $language, $headline), '');
    }

    private static function entry(
        int $entryId,
        int $feedId,
        ?FeedLanguage $language,
        string $headline,
    ): WindowEntryModel {
        $effectiveDate = new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC'));

        return new WindowEntryModel($entryId, $feedId, $language, $headline, false, null, $effectiveDate, null, null);
    }

    private function addCommonHeadlines(int $holders): void
    {
        for ($index = 1; $index <= $holders; $index++) {
            $this->add($index, $index, 'Gemeinsam ' . self::uniqueWord($index), '');
        }
        $this->add($holders + 1, $holders + 1, self::uniqueWord($holders + 1), '');
    }

    private function addTagCompoundHeadlines(int $holders): void
    {
        for ($index = 1; $index <= $holders; $index++) {
            $compound = $index % 2 === 1 ? 'Bundestag' : 'Arbeitstag';
            $this->addIn(FeedLanguage::German, $index, $index, $compound . ' ' . self::uniqueWord($index));
        }
    }

    private static function uniqueWord(int $index): string
    {
        return 'zeta' . pack('C2', 97 + intdiv($index, 26), 97 + $index % 26);
    }
}
