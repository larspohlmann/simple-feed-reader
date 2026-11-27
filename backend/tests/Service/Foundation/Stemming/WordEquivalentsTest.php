<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Stemming\WordEquivalents;
use App\Service\Foundation\Text\Support\CasefoldedWords;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordEquivalentsTest extends TestCase
{
    private WordEquivalents $equivalents;

    protected function setUp(): void
    {
        $this->equivalents = StemmingWiring::equivalents();
    }

    public function testADroppedShortWordLeavesNoGap(): void
    {
        self::assertSame(
            [['streik', 'streik'], ['hamburg', 'hamburg']],
            $this->equivalents->canonicalizeWords('Streik in Hamburg'),
        );
    }

    public function testNatoAndOtanShareOneStem(): void
    {
        self::assertSame(['nato'], $this->stemsOf('NATO', null));
        self::assertSame(['nato'], $this->stemsOf('OTAN', null));
    }

    public function testAPhraseIsConsumedIntoOneStem(): void
    {
        self::assertEqualsCanonicalizing(['onu', 'warn'], $this->stemsOf('United Nations warn', null));
    }

    public function testPhrasesMatchAcrossPunctuationAndCase(): void
    {
        foreach (['Nations-Unies', 'NATIONS UNIES et Chine', 'Union européenne'] as $text) {
            $stems = $this->stemsOf($text, null);
            self::assertNotSame([], array_intersect(['onu', 'eunion'], $stems), $text);
            self::assertSame([], array_intersect(['nation', 'unies', 'union', 'europe'], $stems), $text);
        }
    }

    public function testARepeatedPhraseCountsOnce(): void
    {
        self::assertEqualsCanonicalizing(
            ['onu', 'und'],
            $this->stemsOf('Vereinte Nationen und Vereinte Nationen', null),
        );
    }

    public function testAShortAbbreviationIsKeptAndMapped(): void
    {
        self::assertEqualsCanonicalizing(['eunion', 'ban'], $this->stemsOf('EU to ban AI', null));
    }

    public function testALowerCaseWordIsNotAnAbbreviation(): void
    {
        self::assertNotContains('onu', $this->stemsOf('Un plan de paix pour Gaza', null));
    }

    #[DataProvider('shoutedTexts')]
    public function testAnAbbreviationThatIsAnOrdinaryWordDoesNotFireInAShoutedRun(string $text): void
    {
        self::assertNotContains('onu', $this->stemsOf($text, null));
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
        self::assertContains($canonical, $this->stemsOf($text, null));
    }

    /** @return iterable<string, array{string, string}> */
    public static function abbreviationsNextToCapitals(): iterable
    {
        yield 'EU before an acronym' => ['EU AI Act tritt in Kraft', 'eunion'];
        yield 'whole text in capitals' => ['THE EU AND THE UN', 'eunion'];
    }

    public function testGermanInflectedPhrasesMatch(): void
    {
        $union = $this->stemsOf('Gipfel der Europäischen Union in Brüssel', null);
        self::assertContains('eunion', $union);
        self::assertNotContains('union', $union);
        $nations = $this->stemsOf('Rede bei den Vereinten Nationen', null);
        self::assertContains('onu', $nations);
        self::assertNotContains('nation', $nations);
    }

    public function testAnAbbreviationStandingAloneFires(): void
    {
        self::assertSame(['eunion'], $this->stemsOf('EU', null));
    }

    public function testNeighboursWithLowerCaseLettersDoNotSilenceAnAbbreviation(): void
    {
        self::assertContains('onu', $this->stemsOf('Talks between OpenAI UN AstraZeneca', null));
    }

    public function testSeveralAbbreviationsInOneTextAreAllMapped(): void
    {
        self::assertSame(['eunion', 'and', 'onu', 'agree'], $this->stemsOf('EU and UN agree', null));
    }

    public function testTwoSpellingsOfOneNameCountOnce(): void
    {
        self::assertSame(['nato', 'und'], $this->stemsOf('NATO OTAN und', null));
    }

    public function testAnAbbreviationAtTheEdgesOfMixedTextFires(): void
    {
        self::assertContains('eunion', $this->stemsOf('EU imposes tariffs', null));
        self::assertContains('onu', $this->stemsOf('Tariffs condemned by the UN', null));
    }

    public function testAnAbbreviationInAHyphenatedCompoundFires(): void
    {
        self::assertContains('eunion', $this->stemsOf('Die EU-Kommission legt nach', null));
        self::assertContains('onu', $this->stemsOf('Rede von UN-Generalsekretär Guterres', null));
    }

    public function testAnAbbreviationInsideALongerWordDoesNotFire(): void
    {
        self::assertNotContains('eunion', $this->stemsOf('Preise in EUR steigen', null));
        self::assertNotContains('onu', $this->stemsOf('Der UNHCR warnt', null));
    }

    public function testOneAllCapsNeighbourDoesNotSilenceAnAbbreviation(): void
    {
        self::assertContains('onu', $this->stemsOf('Die UN WHO warnt', null));
    }

    public function testAProceduralTermThatNamesNoStoryIsNotMapped(): void
    {
        $custody = $this->stemsOf('Der Verdächtige sitzt in Untersuchungshaft', null);
        self::assertNotContains('uhaft', $custody);
        self::assertNotContains('uhaft', $this->stemsOf('Richter verhängt U-Haft', null));
    }

    public function testUnlistedWordsAreStemmedAsBefore(): void
    {
        self::assertSame(['ukrain', 'talks'], $this->stemsOf('Ukraine Ukrainian talks', null));
    }

    public function testAHangulTextWithoutALanguageKeepsItsWords(): void
    {
        self::assertCount(3, $this->stemsOf('한국 경제 뉴스', null));
        self::assertCount(3, $this->stemsOf('서울 대통령 선거', null));
    }

    public function testAnUncomposableMarkDoesNotLengthenAWord(): void
    {
        self::assertSame([], $this->stemsOf("ab\u{0303}", null));
    }

    public function testASpacingMarkStaysInsideItsWord(): void
    {
        self::assertSame(['नमस्ते'], CasefoldedWords::splitWords('नमस्ते'));
        self::assertCount(1, $this->stemsOf('नमस्ते', null));
    }

    public function testEveryCanonicalIsAnOpaqueIdNoMapKeysAndNoLanguageSeeds(): void
    {
        $stopWordStems = StemmingWiring::stopWordStems();
        $stemKeys = self::allStemKeys();
        foreach (self::canonicalsByTable() as $label => $canonical) {
            self::assertGreaterThanOrEqual(3, mb_strlen($canonical), $label);
            self::assertSame($canonical, WordStems::cut($canonical), $label);
            self::assertArrayNotHasKey($canonical, $stemKeys, $label);
            foreach ([null, ...FeedLanguage::cases()] as $language) {
                self::assertFalse($stopWordStems->isStopWordStem($canonical, $language), $label);
            }
        }
    }

    public function testEveryStemKeyIsBareAndNoSeedOfItsLanguage(): void
    {
        $stopWordStems = StemmingWiring::stopWordStems();
        foreach (FeedLanguage::cases() as $language) {
            foreach (array_keys(self::stemsByLanguage()[$language->value]) as $stem) {
                self::assertSame(WordStems::bare($stem), $stem, $stem);
                self::assertFalse($stopWordStems->isStopWordStem($stem, $language), $language->value . ' ' . $stem);
            }
        }
    }

    public function testEveryStemKeyIsItsOwnStemInItsLanguage(): void
    {
        $stemmer = StemmingWiring::stemmer();
        foreach (FeedLanguage::cases() as $language) {
            foreach (array_keys(self::stemsByLanguage()[$language->value]) as $stem) {
                self::assertSame($stem, $stemmer->stem($stem, $language), $language->value . ' ' . $stem);
            }
        }
    }

    #[DataProvider('spellingsByLanguage')]
    public function testEachLanguagesSpellingOfAnInstitutionSharesItsCanonical(
        FeedLanguage $language,
        string $text,
        string $canonical,
    ): void {
        self::assertContains($canonical, $this->stemsOf($text, $language));
    }

    /** @return iterable<string, array{FeedLanguage, string, string}> */
    public static function spellingsByLanguage(): iterable
    {
        yield 'de Vereinten Nationen' => [FeedLanguage::German, 'Vereinten Nationen', 'onu'];
        yield 'de Europäischen Union' => [FeedLanguage::German, 'Europäischen Union', 'eunion'];
        yield 'en United Nations' => [FeedLanguage::English, 'United Nations', 'onu'];
        yield 'fr OTAN' => [FeedLanguage::French, 'OTAN', 'nato'];
        yield 'fr Nations unies' => [FeedLanguage::French, 'Nations unies', 'onu'];
        yield 'es OTAN' => [FeedLanguage::Spanish, 'OTAN', 'nato'];
        yield 'es Naciones Unidas' => [FeedLanguage::Spanish, 'Naciones Unidas', 'onu'];
        yield 'fr ONU' => [FeedLanguage::French, "L'ONU condamne", 'onu'];
        yield 'fr UE' => [FeedLanguage::French, "L'UE sanctionne", 'eunion'];
        yield 'fr Union européenne' => [FeedLanguage::French, "L'Union européenne vote", 'eunion'];
        yield 'es ONU' => [FeedLanguage::Spanish, 'La ONU condena', 'onu'];
        yield 'es UE' => [FeedLanguage::Spanish, 'La UE sanciona', 'eunion'];
        yield 'es Unión Europea' => [FeedLanguage::Spanish, 'La Unión Europea vota', 'eunion'];
    }

    #[DataProvider('europeanUnions')]
    public function testTheEuropeanUnionIsOneStemInEveryLanguage(FeedLanguage $language, string $text): void
    {
        self::assertContains('eunion', $this->stemsOf($text, $language));
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
        $stemmer = StemmingWiring::stemmer();

        self::assertSame(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['Bundesregierung', 'beschließt', 'Haushalt'],
            ),
            $this->stemsOf('Bundesregierung beschließt Haushalt', FeedLanguage::German),
        );
    }

    public function testAGermanCompoundAddsTheStemsOfItsParts(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertEqualsCanonicalizing(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['polizeieinsatz', 'polizei', 'einsatz'],
            ),
            $this->stemsOf('Polizeieinsatz', FeedLanguage::German),
        );
    }

    public function testACompoundInAnotherLanguageIsNotSplit(): void
    {
        self::assertCount(1, $this->stemsOf('Polizeieinsatz', FeedLanguage::English));
    }

    public function testACompoundWithoutALanguageKeepsItsCut(): void
    {
        self::assertSame(['polize'], $this->stemsOf('Polizeieinsatz', null));
    }

    public function testAPartThatIsACanonicalKeepsIt(): void
    {
        self::assertContains('nato', $this->stemsOf('Natogipfel', FeedLanguage::German));
    }

    public function testAKnownWordMoreFrequentThanItsPartsStaysOneStem(): void
    {
        self::assertCount(1, $this->stemsOf('Aktion', FeedLanguage::German));
    }

    public function testACanonicalIsNeverSplit(): void
    {
        self::assertSame(['eunion'], $this->equivalents->stemWordAndParts('eunion', 'eunion', FeedLanguage::German));
    }

    public function testTheWholeStemComesBeforeThePartStems(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertSame(
            array_map(
                static fn (string $word): string => $stemmer->stem($word, FeedLanguage::German),
                ['polizeieinsatz', 'polizei', 'einsatz'],
            ),
            $this->equivalents->stemWordAndParts('polizeieinsatz', 'polizeieinsatz', FeedLanguage::German),
        );
    }

    public function testARepeatedPartCountsOnce(): void
    {
        $stemmer = StemmingWiring::stemmer();

        self::assertSame(
            [$stemmer->stem('tagtagbus', FeedLanguage::German), 'tag', 'bus'],
            $this->equivalents->stemWordAndParts('tagtagbus', 'tagtagbus', FeedLanguage::German),
        );
    }

    public function testNoTableHoldsAPersonAnEventOrACountry(): void
    {
        $canonicals = array_values(array_unique(self::canonicalsByTable()));
        sort($canonicals);

        self::assertSame(['eunion', 'nato', 'onu'], $canonicals);
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
            $words = CasefoldedWords::splitWords($phrase);
            self::assertSame(implode(' ', $words), $phrase);
            self::assertSame(WordStems::bare($phrase), $phrase);
            self::assertGreaterThanOrEqual(2, \count($words), $phrase);
        }
    }

    public function testEveryCutStemKeyIsACut(): void
    {
        foreach (array_keys(self::table('CUT_STEMS')) as $stem) {
            self::assertSame($stem, WordStems::cut($stem), $stem);
        }
    }

    /** @return list<string> distinct stems, as a caller takes them word by word */
    private function stemsOf(string $text, ?FeedLanguage $language): array
    {
        $stems = [];
        foreach ($this->equivalents->canonicalizeWords($text) as [$word, $bare]) {
            $stems = [...$stems, ...$this->equivalents->stemWordAndParts($word, $bare, $language)];
        }

        return array_values(array_unique($stems));
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
        $table = new \ReflectionClassConstant(WordEquivalents::class, $name)->getValue();
        self::assertIsArray($table);

        /** @var array<string, string> $table */
        return $table;
    }

    /** @return array<string, array<string, string>> language => stem => canonical */
    private static function stemsByLanguage(): array
    {
        $table = new \ReflectionClassConstant(WordEquivalents::class, 'STEMS_BY_LANGUAGE')->getValue();
        self::assertIsArray($table);

        /** @var array<string, array<string, string>> $table */
        return $table;
    }
}
