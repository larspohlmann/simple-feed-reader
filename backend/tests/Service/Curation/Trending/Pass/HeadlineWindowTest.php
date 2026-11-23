<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\SharedArticleModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class HeadlineWindowTest extends TestCase
{
    private const float ONCE = 6.6214;
    private const float TWICE = 6.2159;
    private const float THRICE = 5.9282;
    private const string CARD_HEADLINE = 'Lighthouse keepers strike again';
    private const string SPANISH_HEADLINE = 'Los fareros vuelven a la huelga';
    private const string FRENCH_HEADLINE = 'Les gardiens de phare reprennent la grève';

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
        $this->add(1, 10, 'NATO warns', '');
        $this->add(2, 20, 'OTAN avertit', '');

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(self::TWICE, $shared[1], 0.0001);
    }

    public function testAFrenchAndASpanishSpellingOfOneInstitutionShareWeight(): void
    {
        $this->addIn(FeedLanguage::French, 1, 10, "L'OTAN avertit");
        $this->addIn(FeedLanguage::Spanish, 2, 20, 'La OTAN advierte');
        $this->addIn(FeedLanguage::French, 3, 30, 'Les Nations unies votent');
        $this->addIn(FeedLanguage::Spanish, 4, 40, 'Las Naciones Unidas votan');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
        self::assertSame([3], array_keys($this->window->sharedWeightsWithEarlier(4)));
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

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(self::TWICE, $shared[1], 0.0001, 'polizei alone');
    }

    public function testTwoCompoundsShareTheirCommonPart(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz am Bahnhof');
        $this->addIn(FeedLanguage::German, 2, 20, 'Feuerwehreinsatz in Köln');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'einsatz');
    }

    public function testASharedCompoundWeighsAsOneWordAndNotAgainThroughItsParts(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz');
        $this->addIn(FeedLanguage::German, 2, 20, 'Polizeieinsatz');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
        self::assertEqualsWithDelta(self::TWICE, $this->window->totalWeight(1), 0.0001, 'the share is whole');
    }

    public function testAPartRepeatingASharedCompoundOfEitherEntryIsNotCountedAgain(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz');
        $this->addIn(FeedLanguage::German, 2, 20, 'Polizeieinsatz der Polizei');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
    }

    public function testAPartRepeatsACompoundTheEarlierEntryHoldsUnsplit(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Rathäuser, Haus');
        $this->addIn(FeedLanguage::German, 2, 20, 'Rathaus');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'rathaus');
    }

    public function testAPartRepeatsACompoundTheLaterEntryHoldsUnsplit(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Rathaus');
        $this->addIn(FeedLanguage::German, 2, 20, 'Rathäuser, Haus');

        self::assertEqualsWithDelta(self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'rathaus');
    }

    public function testAPartCountsWhenOneOfItsCompoundsIsNotShared(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz und Feuerwehreinsatz');
        $this->addIn(FeedLanguage::German, 2, 20, 'Polizeieinsatz und Kindereinsatz');

        self::assertEqualsWithDelta(
            2 * self::TWICE,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'polizeieinsatz, and einsatz through feuerwehreinsatz and kindereinsatz; polizei only repeats the compound',
        );
    }

    public function testTheWordsACompoundGivesShareNoMoreThanTheCompoundWeighs(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz');
        $this->addIn(FeedLanguage::German, 2, 20, 'Einsatz der Polizei');

        self::assertEqualsWithDelta(self::ONCE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'the compound');
    }

    public function testTheCapHoldsForTheLaterEntrysCompoundToo(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Einsatz der Polizei');
        $this->addIn(FeedLanguage::German, 2, 20, 'Polizeieinsatz');

        self::assertEqualsWithDelta(self::ONCE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'the compound');
    }

    public function testPartsBothEntriesHoldOnlyAsPartsShareNoMoreThanEitherCompoundWeighs(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Kinderarzt');
        $this->addIn(FeedLanguage::German, 2, 20, 'Kinderarztpraxis');

        self::assertEqualsWithDelta(self::ONCE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001, 'either one');
    }

    public function testACompoundSharesItsWholePartsWithItsSplitSpellingWhenThatEntryIsTheLighter(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Huang: Spionageverdacht im Senat');
        $this->addIn(FeedLanguage::German, 2, 20, 'Huang: Spionage-Verdacht');

        self::assertEqualsWithDelta(3 * self::TWICE, $this->window->totalWeight(2), 0.001);
        self::assertEqualsWithDelta(
            3 * self::TWICE,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.001,
            'huang, spionage and verdacht, each a word of the lighter entry',
        );
    }

    public function testACompoundsShareOfItsOwnEntryStaysWholeAgainstItsSplitSpelling(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Spionage-Verdacht im Senat');
        $this->addIn(FeedLanguage::German, 2, 20, 'Spionageverdacht');

        self::assertEqualsWithDelta(
            $this->window->totalWeight(2),
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'the compound, which is all its entry weighs',
        );
    }

    public function testPartsBothEntriesHoldOnlyAsPartsAreCappedByBothCompoundsWhenTheEarlierIsLighter(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Kinderarzt Bett');
        $this->window->add(self::entry(2, 20, FeedLanguage::German, 'Bett'), 'Kinderarztpraxis Russland Schule', null);

        self::assertEqualsWithDelta(
            self::TWICE + self::ONCE / 2,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'bett, then kinder and arzt within kinderarztpraxis at body weight, though kinderarzt alone allows more',
        );
    }

    public function testPartsBothEntriesHoldOnlyAsPartsAreCappedByBothCompoundsWhenTheLaterIsLighter(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Kinderarzt Bett Schule');
        $this->window->add(self::entry(2, 20, FeedLanguage::German, 'Bett'), 'Kinderarztpraxis Russland', null);

        self::assertEqualsWithDelta(
            self::TWICE + self::ONCE / 2,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'bett, then kinder and arzt within kinderarztpraxis at body weight',
        );
    }

    public function testPartsTheLighterEntryHoldsAsWordsCountWholeThoughTheOtherHoldsThemInABodyCompound(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Kinder Arzt Bett');
        $body = 'Kinderarztpraxis Russland Schule Polizei';
        $this->window->add(self::entry(2, 20, FeedLanguage::German, 'Bett'), $body, null);

        self::assertGreaterThan($this->window->totalWeight(1), $this->window->totalWeight(2));
        self::assertEqualsWithDelta(
            2 * self::TWICE,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'bett, then kinder and arzt at body weight, more than the body compound weighs',
        );
    }

    public function testPartsOfEntriesOfEqualWeightAreCappedByBothEntriesCompounds(): void
    {
        $this->window->add(self::entry(1, 10, FeedLanguage::German, 'Bett'), 'Kinder Arzt Polizeieinsatz', null);
        $this->window->add(self::entry(2, 20, FeedLanguage::German, 'Bett'), 'Kinderarztpraxis Polizei Einsatz', null);

        self::assertSame($this->window->totalWeight(1), $this->window->totalWeight(2));
        self::assertEqualsWithDelta(
            self::TWICE + self::ONCE,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'neither entry is the lighter, so each compound caps its parts at its half weight',
        );
    }

    public function testACompoundInTheBodyCapsItsPartsAtItsBodyWeight(): void
    {
        $this->window->add(self::entry(1, 10, FeedLanguage::German, 'Bahnhof'), 'Polizeieinsatz', null);
        $this->addIn(FeedLanguage::German, 2, 20, 'Bahnhof: Einsatz der Polizei');

        self::assertEqualsWithDelta(
            self::TWICE + self::ONCE / 2,
            $this->window->sharedWeightsWithEarlier(2)[1],
            0.0001,
            'bahnhof, then polizei and einsatz through the body compound, which weighs half',
        );
    }

    public function testAnEntryWeighsAPartThatIsAlsoOneOfItsWordsOnce(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizei: Polizeieinsatz');

        self::assertEqualsWithDelta(2 * self::ONCE, $this->window->totalWeight(1), 0.0001, 'einsatz is only a part');
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

    public function testAPostSharesItsCardsHeadlineWithAnArticleInTheCardsLanguage(): void
    {
        $this->addIn(FeedLanguage::English, 1, 10, self::CARD_HEADLINE);
        $this->addSharing(
            self::post(2, FeedLanguage::German, 'Schaut euch das an'),
            new SharedArticleModel(self::CARD_HEADLINE, '', FeedLanguage::English),
        );

        $shared = $this->window->sharedWeightsWithEarlier(2);

        self::assertSame([1], array_keys($shared));
        self::assertEqualsWithDelta(3 * self::TWICE, $shared[1], 0.001);
    }

    public function testAPostWithoutItsCardSharesNothingWithTheArticle(): void
    {
        $this->addIn(FeedLanguage::English, 1, 10, self::CARD_HEADLINE);
        $this->addIn(FeedLanguage::German, 2, 20, 'Schaut euch das an');

        self::assertSame([], $this->window->sharedWeightsWithEarlier(2));
    }

    public function testACardsTitleIsStemmedInTheCardsLanguageNotThePosts(): void
    {
        $this->addIn(FeedLanguage::English, 1, 10, 'Striking keepers reopening');
        $this->addSharing(self::post(2, FeedLanguage::German, 'Lesenswert'), new SharedArticleModel(
            'Striking keepers reopening',
            '',
            FeedLanguage::German,
        ));

        self::assertSame([], $this->window->sharedWeightsWithEarlier(2), 'German keeps striking and reopening whole');
    }

    public function testACardsDescriptionCountsAsBody(): void
    {
        $this->add(1, 10, 'Storm Hamburg', '');
        $this->addSharing(
            self::post(2, null, 'Hamburg harbour'),
            new SharedArticleModel('Hamburg harbour', 'Storm surge', null),
        );

        self::assertEqualsWithDelta(1.5 * self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
    }

    public function testACardDescriptionStemTheHeadlineHoldsCountsAsHeadline(): void
    {
        $this->add(1, 10, 'Storm Hamburg', '');
        $this->addSharing(self::post(2, null, 'Storm Hamburg'), new SharedArticleModel('Ferry', 'Storm Hamburg', null));

        self::assertEqualsWithDelta(2 * self::TWICE, $this->window->sharedWeightsWithEarlier(2)[1], 0.0001);
    }

    public function testACardTitledLikeItsPostAddsNoWeightTwice(): void
    {
        $this->add(1, 10, 'Storm Hamburg', '');
        $this->addSharing(self::post(2, null, 'Storm Hamburg'), new SharedArticleModel('Storm Hamburg', '', null));

        self::assertEqualsWithDelta(2 * self::TWICE, $this->window->totalWeight(2), 0.0001);
    }

    public function testACompoundsPartsInACardStayPartsAsInTheEntrysOwnHeadline(): void
    {
        $this->addIn(FeedLanguage::German, 1, 10, 'Polizeieinsatz');
        $this->addSharing(
            self::post(2, FeedLanguage::German, ''),
            new SharedArticleModel('Polizeieinsatz', '', FeedLanguage::German),
        );

        self::assertEqualsWithDelta($this->window->totalWeight(1), $this->window->totalWeight(2), 0.0001);
    }

    public function testAFrenchPostSharesASpanishCardsHeadlineWithASpanishArticle(): void
    {
        $this->addIn(FeedLanguage::Spanish, 1, 10, self::SPANISH_HEADLINE);
        $this->addSharing(self::post(2, FeedLanguage::French, 'À lire'), new SharedArticleModel(
            self::SPANISH_HEADLINE,
            '',
            FeedLanguage::Spanish,
        ));

        self::assertSame([1], array_keys($this->window->sharedWeightsWithEarlier(2)));
    }

    public function testASpanishPostSharesAFrenchCardsHeadlineWithAFrenchArticle(): void
    {
        $this->addIn(FeedLanguage::French, 1, 10, self::FRENCH_HEADLINE);
        $this->addSharing(self::post(2, FeedLanguage::Spanish, 'Para leer'), new SharedArticleModel(
            self::FRENCH_HEADLINE,
            '',
            FeedLanguage::French,
        ));

        self::assertSame([1], array_keys($this->window->sharedWeightsWithEarlier(2)));
    }

    public function testHeadlineStemIdsLeaveOutBodyStems(): void
    {
        $this->add(1, 10, 'Storm floods', 'Harbour warning');

        self::assertCount(2, $this->window->collectHeadlineStemIds(1));
    }

    public function testHeadlineStemIdsLeaveOutFeedFiller(): void
    {
        for ($index = 1; $index <= 4; $index++) {
            $this->add($index, 10, 'Briefing ' . self::uniqueWord($index), '');
        }

        self::assertSame([0], array_keys($this->window->collectHeadlineStemIds(1)));
    }

    public function testRarityFollowsDocumentFrequency(): void
    {
        $this->add(1, 10, 'Storm', '');
        $this->add(2, 20, 'Storm', '');

        [$stemId] = $this->window->collectHeadlineStemIds(1);

        self::assertEqualsWithDelta(self::TWICE, $this->window->computeRarity($stemId), 0.0001);
    }

    private function add(int $entryId, int $feedId, string $headline, string $body): void
    {
        $this->window->add(self::entry($entryId, $feedId, null, $headline), $body, null);
    }

    private function addSharing(WindowEntryModel $post, SharedArticleModel $article): void
    {
        $this->window->add($post, '', $article);
    }

    private static function post(int $entryId, ?FeedLanguage $language, string $headline): WindowEntryModel
    {
        return self::entry($entryId, $entryId * 10, $language, $headline);
    }

    private function addIn(FeedLanguage $language, int $entryId, int $feedId, string $headline): void
    {
        $this->window->add(self::entry($entryId, $feedId, $language, $headline), '', null);
    }

    private static function entry(
        int $entryId,
        int $feedId,
        ?FeedLanguage $language,
        string $headline,
    ): WindowEntryModel {
        $effectiveDate = new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC'));
        $links = new EntryLinksModel(null, null, null);

        return new WindowEntryModel($entryId, $feedId, $language, $headline, false, null, $effectiveDate, $links);
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
