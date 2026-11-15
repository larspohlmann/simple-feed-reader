<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\Support\HeadlineStems;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadlineEquivalentsTest extends TestCase
{
    private HeadlineEquivalents $equivalents;

    protected function setUp(): void
    {
        $this->equivalents = TrendingWiring::equivalents();
    }

    public function testCrossLanguageNamesShareOneStem(): void
    {
        foreach (['Zelensky', 'Zelenskyy', 'Selenskyj', 'Zelenski'] as $name) {
            self::assertSame(['selens'], $this->equivalents->stemsOf($name, null), $name);
        }
    }

    public function testAPhraseIsConsumedIntoOneStem(): void
    {
        self::assertEqualsCanonicalizing(['onu', 'warn'], $this->equivalents->stemsOf('United Nations warn', null));
    }

    public function testPhrasesMatchAcrossPunctuationAndCase(): void
    {
        foreach (['Nations-Unies', 'NATIONS UNIES et Chine', 'Union européenne'] as $text) {
            $stems = $this->equivalents->stemsOf($text, null);
            self::assertNotSame([], array_intersect(['onu', 'eunion'], $stems), $text);
            self::assertSame([], array_intersect(['nation', 'unies', 'union', 'europe'], $stems), $text);
        }
    }

    public function testARepeatedPhraseCountsOnce(): void
    {
        self::assertEqualsCanonicalizing(
            ['onu', 'und'],
            $this->equivalents->stemsOf('Vereinte Nationen und Vereinte Nationen', null),
        );
    }

    public function testAShortAbbreviationIsKeptAndMapped(): void
    {
        self::assertEqualsCanonicalizing(['eunion', 'ban'], $this->equivalents->stemsOf('EU to ban AI', null));
    }

    public function testALowerCaseWordIsNotAnAbbreviation(): void
    {
        self::assertNotContains('onu', $this->equivalents->stemsOf('Un plan de paix pour Gaza', null));
    }

    #[DataProvider('shoutedTexts')]
    public function testAnAbbreviationThatIsAnOrdinaryWordDoesNotFireInAShoutedRun(string $text): void
    {
        self::assertNotContains('onu', $this->equivalents->stemsOf($text, null));
    }

    /** @return iterable<string, array{string}> */
    public static function shoutedTexts(): iterable
    {
        yield 'French in capitals' => ['UN PLAN DE PAIX'];
        yield 'between all-caps words' => ['Breaking: WHO UN NEWS today'];
        yield 'at the start, before an all-caps word' => ['UNO WILL FALL, critics say'];
        yield 'next to a two-letter all-caps word' => ['THE UN UK DEAL is done'];
    }

    #[DataProvider('abbreviationsNextToCapitals')]
    public function testAnUnambiguousAbbreviationFiresNextToCapitals(string $text, string $canonical): void
    {
        self::assertContains($canonical, $this->equivalents->stemsOf($text, null));
    }

    /** @return iterable<string, array{string, string}> */
    public static function abbreviationsNextToCapitals(): iterable
    {
        yield 'EU before an acronym' => ['EU AI Act tritt in Kraft', 'eunion'];
        yield 'WM after an acronym' => ['Tickets für die FIFA WM', 'weltme'];
        yield 'whole text in capitals' => ['THE EU AND THE UN', 'eunion'];
    }

    public function testALongFormNextToAnAbbreviationStillMaps(): void
    {
        self::assertContains('britai', $this->equivalents->stemsOf('Großbritanniens EU-Austritt', null));
        self::assertContains('osterr', $this->equivalents->stemsOf('ÖVP Austria', null));
    }

    public function testGermanInflectedPhrasesMatch(): void
    {
        $union = $this->equivalents->stemsOf('Gipfel der Europäischen Union in Brüssel', null);
        self::assertContains('eunion', $union);
        self::assertNotContains('union', $union);
        $nations = $this->equivalents->stemsOf('Rede bei den Vereinten Nationen', null);
        self::assertContains('onu', $nations);
        self::assertNotContains('nation', $nations);
    }

    public function testGermanGenitivesMatch(): void
    {
        self::assertContains('poland', $this->equivalents->stemsOf('Polens Regierung', null));
        self::assertContains('china', $this->equivalents->stemsOf('Chinas Exporte', null));
        self::assertContains('japan', $this->equivalents->stemsOf('Japans Premier', null));
        self::assertContains('kremli', $this->equivalents->stemsOf('Sprecher des Kremls', null));
    }

    public function testAnAbbreviationStandingAloneFires(): void
    {
        self::assertSame(['eunion'], $this->equivalents->stemsOf('EU', null));
    }

    public function testNeighboursWithLowerCaseLettersDoNotSilenceAnAbbreviation(): void
    {
        self::assertContains('onu', $this->equivalents->stemsOf('Talks between OpenAI UN AstraZeneca', null));
    }

    public function testSeveralAbbreviationsInOneTextAreAllMapped(): void
    {
        self::assertSame(['eunion', 'and', 'onu', 'agree'], $this->equivalents->stemsOf('EU and UN agree', null));
    }

    public function testTwoSpellingsOfOneNameCountOnce(): void
    {
        self::assertSame(['russia', 'und'], $this->equivalents->stemsOf('Russland Russia und', null));
    }

    public function testAnAbbreviationAtTheEdgesOfMixedTextFires(): void
    {
        self::assertContains('eunion', $this->equivalents->stemsOf('EU imposes tariffs', null));
        self::assertContains('onu', $this->equivalents->stemsOf('Tariffs condemned by the UN', null));
    }

    public function testAnAbbreviationInAHyphenatedCompoundFires(): void
    {
        self::assertContains('weltme', $this->equivalents->stemsOf('Eishockey-WM: DEB-Frauen scheitern', null));
        self::assertContains('eunion', $this->equivalents->stemsOf('Die EU-Kommission legt nach', null));
        self::assertContains('onu', $this->equivalents->stemsOf('Rede von UN-Generalsekretär Guterres', null));
    }

    public function testAnAbbreviationInsideALongerWordDoesNotFire(): void
    {
        self::assertNotContains('eunion', $this->equivalents->stemsOf('Preise in EUR steigen', null));
        self::assertNotContains('onu', $this->equivalents->stemsOf('Der UNHCR warnt', null));
    }

    public function testOneAllCapsNeighbourDoesNotSilenceAnAbbreviation(): void
    {
        self::assertContains('weltme', $this->equivalents->stemsOf('Traum von WM DEB platzt', null));
    }

    public function testALongFormWhoseStemCollidesIsAnExactWord(): void
    {
        self::assertContains('britai', $this->equivalents->stemsOf('Großbritannien verschärft Regeln', null));
        self::assertSame(['grossb', 'hambur'], $this->equivalents->stemsOf('Großbrand Hamburg', null));
    }

    public function testAProceduralTermThatNamesNoStoryIsNotMapped(): void
    {
        $custody = $this->equivalents->stemsOf('Der Verdächtige sitzt in Untersuchungshaft', null);
        self::assertNotContains('uhaft', $custody);
        self::assertNotContains('uhaft', $this->equivalents->stemsOf('Richter verhängt U-Haft', null));
    }

    public function testUnlistedWordsAreStemmedAsBefore(): void
    {
        self::assertSame(['ukrain', 'talks'], $this->equivalents->stemsOf('Ukraine Ukrainian talks', null));
    }

    public function testAHangulHeadlineWithoutALanguageKeepsItsWords(): void
    {
        self::assertCount(3, $this->equivalents->stemsOf('한국 경제 뉴스', null));
        self::assertCount(3, $this->equivalents->stemsOf('서울 대통령 선거', null));
    }

    public function testAnUncomposableMarkDoesNotLengthenAWord(): void
    {
        self::assertSame([], $this->equivalents->stemsOf("ab\u{0303}", null));
    }

    public function testASpacingMarkStaysInsideItsWord(): void
    {
        self::assertSame(['नमस्ते'], HeadlineStems::words('नमस्ते'));
        self::assertCount(1, $this->equivalents->stemsOf('नमस्ते', null));
    }

    public function testEveryCanonicalIsAnOpaqueIdNoMapKeysAndNoLanguageSeeds(): void
    {
        $filler = TrendingWiring::filler();
        $stemKeys = self::allStemKeys();
        foreach (self::canonicalsByTable() as $label => $canonical) {
            self::assertGreaterThanOrEqual(3, mb_strlen($canonical), $label);
            self::assertSame($canonical, HeadlineStems::cut($canonical), $label);
            self::assertArrayNotHasKey($canonical, $stemKeys, $label);
            foreach ([null, ...FeedLanguage::cases()] as $language) {
                self::assertFalse($filler->isSeed($canonical, $language), $label);
            }
        }
    }

    public function testEveryStemKeyIsBareAndNoSeedOfItsLanguage(): void
    {
        $filler = TrendingWiring::filler();
        foreach (FeedLanguage::cases() as $language) {
            foreach (array_keys(self::stemsByLanguage()[$language->value]) as $stem) {
                self::assertSame(HeadlineStems::bare($stem), $stem, $stem);
                self::assertFalse($filler->isSeed($stem, $language), $language->value . ' ' . $stem);
            }
        }
    }

    public function testEveryStemKeyIsItsOwnStemInItsLanguage(): void
    {
        $stemmer = TrendingWiring::stemmer();
        foreach (FeedLanguage::cases() as $language) {
            foreach (array_keys(self::stemsByLanguage()[$language->value]) as $stem) {
                self::assertSame($stem, $stemmer->stem($stem, $language), $language->value . ' ' . $stem);
            }
        }
    }

    #[DataProvider('spellingsByLanguage')]
    public function testEachLanguagesSpellingOfAPlaceSharesItsCanonical(
        FeedLanguage $language,
        string $text,
        string $canonical,
    ): void {
        self::assertContains($canonical, $this->equivalents->stemsOf($text, $language));
    }

    /** @return iterable<string, array{FeedLanguage, string, string}> */
    public static function spellingsByLanguage(): iterable
    {
        yield 'de Selenskyj' => [FeedLanguage::German, 'Selenskyj', 'selens'];
        yield 'de Russlands' => [FeedLanguage::German, 'Russlands', 'russia'];
        yield 'de Polen' => [FeedLanguage::German, 'Polen', 'poland'];
        yield 'de Österreich' => [FeedLanguage::German, 'Österreich', 'osterr'];
        yield 'de Brüssel' => [FeedLanguage::German, 'Brüssel', 'brusse'];
        yield 'en Zelensky' => [FeedLanguage::English, 'Zelensky', 'selens'];
        yield 'en Kremlin' => [FeedLanguage::English, 'Kremlin', 'kremli'];
        yield 'en France' => [FeedLanguage::English, 'France', 'france'];
        yield 'en Beijing' => [FeedLanguage::English, 'Beijing', 'beijin'];
        yield 'fr Russie' => [FeedLanguage::French, 'Russie', 'russia'];
        yield 'fr Chine' => [FeedLanguage::French, 'Chine', 'china'];
        yield 'fr Pékin' => [FeedLanguage::French, 'Pékin', 'beijin'];
        yield 'fr Bruxelles' => [FeedLanguage::French, 'Bruxelles', 'brusse'];
        yield 'es Rusia' => [FeedLanguage::Spanish, 'Rusia', 'russia'];
        yield 'es Ucrania' => [FeedLanguage::Spanish, 'Ucrania', 'ukrain'];
        yield 'es España' => [FeedLanguage::Spanish, 'España', 'spain'];
        yield 'es México' => [FeedLanguage::Spanish, 'México', 'mexico'];
        yield 'es Francia' => [FeedLanguage::Spanish, 'Francia', 'france'];
    }

    #[DataProvider('europeanUnions')]
    public function testTheEuropeanUnionIsOneStemInEveryLanguage(FeedLanguage $language, string $text): void
    {
        self::assertContains('eunion', $this->equivalents->stemsOf($text, $language));
    }

    /** @return iterable<string, array{FeedLanguage, string}> */
    public static function europeanUnions(): iterable
    {
        yield 'de' => [FeedLanguage::German, 'Europäische Union'];
        yield 'de compound' => [FeedLanguage::German, 'Die EU-Kommission legt nach'];
        yield 'es' => [FeedLanguage::Spanish, 'Unión Europea'];
        yield 'en' => [FeedLanguage::English, 'European Union'];
        yield 'fr' => [FeedLanguage::French, 'Union européenne'];
    }

    public function testAGermanWordIsStemmedWithTheGermanStemmer(): void
    {
        $stemmer = TrendingWiring::stemmer();

        self::assertSame(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['Bundesregierung', 'beschließt', 'Haushalt'],
            ),
            $this->equivalents->stemsOf('Bundesregierung beschließt Haushalt', FeedLanguage::German),
        );
    }

    public function testAGermanCompoundAddsTheStemsOfItsParts(): void
    {
        $stemmer = TrendingWiring::stemmer();

        self::assertEqualsCanonicalizing(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['polizeieinsatz', 'polizei', 'einsatz'],
            ),
            $this->equivalents->stemsOf('Polizeieinsatz', FeedLanguage::German),
        );
    }

    public function testACompoundInAnotherLanguageIsNotSplit(): void
    {
        self::assertCount(1, $this->equivalents->stemsOf('Polizeieinsatz', FeedLanguage::English));
    }

    public function testACompoundWithoutALanguageKeepsItsCut(): void
    {
        self::assertSame(['polize'], $this->equivalents->stemsOf('Polizeieinsatz', null));
    }

    public function testAPartGoesThroughTheGermanEquivalences(): void
    {
        self::assertContains('russia', $this->equivalents->stemsOf('Russlandreise', FeedLanguage::German));
    }

    public function testAKnownWordMoreFrequentThanItsPartsStaysOneStem(): void
    {
        self::assertCount(1, $this->equivalents->stemsOf('Aktion', FeedLanguage::German));
    }

    public function testACanonicalIsNeverSplit(): void
    {
        self::assertSame(['russia'], $this->equivalents->wordStemsOf('russia', 'russia', FeedLanguage::German));
    }

    public function testTheWholeStemComesBeforeThePartStems(): void
    {
        $stemmer = TrendingWiring::stemmer();

        self::assertSame(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['polizeieinsatz', 'polizei', 'einsatz'],
            ),
            $this->equivalents->wordStemsOf('polizeieinsatz', 'polizeieinsatz', FeedLanguage::German),
        );
    }

    public function testALanguageStemThatIsACanonicalNeedsNoEntry(): void
    {
        self::assertSame(['ukrain'], $this->equivalents->stemsOf('Ukraine', FeedLanguage::German));
    }

    public function testEveryExactWordIsLettersAndDigitsOnly(): void
    {
        foreach (array_keys(self::table('EXACT_WORDS')) as $word) {
            self::assertMatchesRegularExpression('/^[\p{L}\p{N}]+$/u', $word);
        }
    }

    public function testEveryShoutableWordIsAnExactWord(): void
    {
        self::assertSame([], array_diff_key(self::table('SHOUTABLE_WORDS'), self::table('EXACT_WORDS')));
    }

    public function testNoPhraseStartsAnotherPhrase(): void
    {
        $phrases = array_keys(self::table('PHRASES'));
        foreach ($phrases as $phrase) {
            foreach ($phrases as $other) {
                self::assertFalse($phrase !== $other && str_starts_with($other, $phrase . ' '), $other);
            }
        }
    }

    public function testEveryPhraseKeyIsInFoldedForm(): void
    {
        foreach (array_keys(self::table('PHRASES')) as $phrase) {
            $words = HeadlineStems::words($phrase);
            self::assertSame(implode(' ', $words), $phrase);
            self::assertSame(HeadlineStems::bare($phrase), $phrase);
            self::assertGreaterThanOrEqual(2, \count($words), $phrase);
        }
    }

    public function testEveryCutStemKeyIsACut(): void
    {
        foreach (array_keys(self::table('CUT_STEMS')) as $stem) {
            self::assertSame($stem, HeadlineStems::cut($stem), $stem);
        }
    }

    /** @return array<string, string> table and key => canonical */
    private static function canonicalsByTable(): array
    {
        $canonicals = [];
        foreach (['EXACT_WORDS', 'PHRASES', 'CUT_STEMS'] as $table) {
            foreach (self::table($table) as $key => $canonical) {
                $canonicals[\sprintf('%s[%s]', $table, $key)] = $canonical;
            }
        }
        foreach (self::stemsByLanguage() as $language => $stems) {
            foreach ($stems as $key => $canonical) {
                $canonicals[\sprintf('STEMS_BY_LANGUAGE[%s][%s]', $language, $key)] = $canonical;
            }
        }

        return $canonicals;
    }

    /** @return array<string, true> */
    private static function allStemKeys(): array
    {
        $keys = array_keys(self::table('CUT_STEMS'));
        foreach (self::stemsByLanguage() as $stems) {
            $keys = [...$keys, ...array_keys($stems)];
        }

        return array_fill_keys($keys, true);
    }

    /** @return array<string, string> */
    private static function table(string $name): array
    {
        $table = new \ReflectionClassConstant(HeadlineEquivalents::class, $name)->getValue();
        self::assertIsArray($table);

        /** @var array<string, string> $table */
        return $table;
    }

    /** @return array<string, array<string, string>> language => stem => canonical */
    private static function stemsByLanguage(): array
    {
        $table = new \ReflectionClassConstant(HeadlineEquivalents::class, 'STEMS_BY_LANGUAGE')->getValue();
        self::assertIsArray($table);

        /** @var array<string, array<string, string>> $table */
        return $table;
    }
}
