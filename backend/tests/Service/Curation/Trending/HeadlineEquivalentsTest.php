<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\Support\HeadlineStems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadlineEquivalentsTest extends TestCase
{
    private HeadlineEquivalents $equivalents;

    protected function setUp(): void
    {
        $this->equivalents = new HeadlineEquivalents();
    }

    public function testCrossLanguageNamesShareOneStem(): void
    {
        foreach (['Zelensky', 'Zelenskyy', 'Selenskyj', 'Zelenski'] as $name) {
            self::assertSame(['selens'], $this->equivalents->stemsOf($name), $name);
        }
    }

    public function testAPhraseIsConsumedIntoOneStem(): void
    {
        self::assertEqualsCanonicalizing(['onu', 'warn'], $this->equivalents->stemsOf('United Nations warn'));
    }

    public function testPhrasesMatchAcrossPunctuationAndCase(): void
    {
        foreach (['Nations-Unies', 'NATIONS UNIES et Chine', 'Union européenne'] as $text) {
            $stems = $this->equivalents->stemsOf($text);
            self::assertNotSame([], array_intersect(['onu', 'eunion'], $stems), $text);
            self::assertSame([], array_intersect(['nation', 'unies', 'union', 'europe'], $stems), $text);
        }
    }

    public function testARepeatedPhraseCountsOnce(): void
    {
        self::assertEqualsCanonicalizing(
            ['onu', 'und'],
            $this->equivalents->stemsOf('Vereinte Nationen und Vereinte Nationen'),
        );
    }

    public function testAShortAbbreviationIsKeptAndMapped(): void
    {
        self::assertEqualsCanonicalizing(['eunion', 'ban'], $this->equivalents->stemsOf('EU to ban AI'));
    }

    public function testALowerCaseWordIsNotAnAbbreviation(): void
    {
        self::assertNotContains('onu', $this->equivalents->stemsOf('Un plan de paix pour Gaza'));
    }

    #[DataProvider('shoutedTexts')]
    public function testAnAbbreviationThatIsAnOrdinaryWordDoesNotFireInAShoutedRun(string $text): void
    {
        self::assertNotContains('onu', $this->equivalents->stemsOf($text));
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
        self::assertContains($canonical, $this->equivalents->stemsOf($text));
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
        self::assertContains('britai', $this->equivalents->stemsOf('Großbritanniens EU-Austritt'));
        self::assertContains('osterr', $this->equivalents->stemsOf('ÖVP Austria'));
    }

    public function testGermanInflectedPhrasesMatch(): void
    {
        $union = $this->equivalents->stemsOf('Gipfel der Europäischen Union in Brüssel');
        self::assertContains('eunion', $union);
        self::assertNotContains('union', $union);
        $nations = $this->equivalents->stemsOf('Rede bei den Vereinten Nationen');
        self::assertContains('onu', $nations);
        self::assertNotContains('nation', $nations);
    }

    public function testGermanGenitivesMatch(): void
    {
        self::assertContains('poland', $this->equivalents->stemsOf('Polens Regierung'));
        self::assertContains('china', $this->equivalents->stemsOf('Chinas Exporte'));
        self::assertContains('japan', $this->equivalents->stemsOf('Japans Premier'));
        self::assertContains('kremli', $this->equivalents->stemsOf('Sprecher des Kremls'));
    }

    public function testAnAbbreviationStandingAloneFires(): void
    {
        self::assertSame(['eunion'], $this->equivalents->stemsOf('EU'));
    }

    public function testNeighboursWithLowerCaseLettersDoNotSilenceAnAbbreviation(): void
    {
        self::assertContains('onu', $this->equivalents->stemsOf('Talks between OpenAI UN AstraZeneca'));
    }

    public function testSeveralAbbreviationsInOneTextAreAllMapped(): void
    {
        self::assertSame(['eunion', 'and', 'onu', 'agree'], $this->equivalents->stemsOf('EU and UN agree'));
    }

    public function testTwoSpellingsOfOneNameCountOnce(): void
    {
        self::assertSame(['russia', 'und'], $this->equivalents->stemsOf('Russland Russia und'));
    }

    public function testAnAbbreviationAtTheEdgesOfMixedTextFires(): void
    {
        self::assertContains('eunion', $this->equivalents->stemsOf('EU imposes tariffs'));
        self::assertContains('onu', $this->equivalents->stemsOf('Tariffs condemned by the UN'));
    }

    public function testAnAbbreviationInAHyphenatedCompoundFires(): void
    {
        self::assertContains('weltme', $this->equivalents->stemsOf('Eishockey-WM: DEB-Frauen scheitern'));
        self::assertContains('eunion', $this->equivalents->stemsOf('Die EU-Kommission legt nach'));
        self::assertContains('onu', $this->equivalents->stemsOf('Rede von UN-Generalsekretär Guterres'));
    }

    public function testAnAbbreviationInsideALongerWordDoesNotFire(): void
    {
        self::assertNotContains('eunion', $this->equivalents->stemsOf('Preise in EUR steigen'));
        self::assertNotContains('onu', $this->equivalents->stemsOf('Der UNHCR warnt'));
    }

    public function testOneAllCapsNeighbourDoesNotSilenceAnAbbreviation(): void
    {
        self::assertContains('weltme', $this->equivalents->stemsOf('Traum von WM DEB platzt'));
    }

    public function testALongFormWhoseStemCollidesIsAnExactWord(): void
    {
        self::assertContains('britai', $this->equivalents->stemsOf('Großbritannien verschärft Regeln'));
        self::assertSame(['grossb', 'hambur'], $this->equivalents->stemsOf('Großbrand Hamburg'));
    }

    public function testAProceduralTermThatNamesNoStoryIsNotMapped(): void
    {
        self::assertNotContains('uhaft', $this->equivalents->stemsOf('Der Verdächtige sitzt in Untersuchungshaft'));
        self::assertNotContains('uhaft', $this->equivalents->stemsOf('Richter verhängt U-Haft'));
    }

    public function testUnlistedWordsAreStemmedAsBefore(): void
    {
        self::assertSame(['ukrain', 'talks'], $this->equivalents->stemsOf('Ukraine Ukrainian talks'));
    }

    public function testEveryCanonicalStemIsInStemForm(): void
    {
        $filler = new HeadlineFiller();
        $stemTable = self::table('STEMS');
        foreach (['EXACT_WORDS', 'SHOUTABLE_WORDS', 'PHRASES', 'STEMS'] as $table) {
            foreach (self::table($table) as $key => $canonical) {
                $label = \sprintf('%s[%s] = %s', $table, $key, $canonical);
                self::assertGreaterThanOrEqual(3, mb_strlen($canonical), $label);
                self::assertSame([$canonical], HeadlineStems::ofWords(HeadlineStems::words($canonical)), $label);
                self::assertFalse($filler->isSeed($canonical), $label);
                self::assertArrayNotHasKey($canonical, $stemTable, $label);
            }
        }
    }

    public function testEveryExactWordIsLettersAndDigitsOnly(): void
    {
        foreach ([...array_keys(self::table('EXACT_WORDS')), ...array_keys(self::table('SHOUTABLE_WORDS'))] as $word) {
            self::assertMatchesRegularExpression('/^[\p{L}\p{N}]+$/u', $word);
        }
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
            self::assertGreaterThanOrEqual(2, \count($words), $phrase);
        }
    }

    public function testEveryStemKeyIsAStem(): void
    {
        foreach (array_keys(self::table('STEMS')) as $stem) {
            self::assertSame([$stem], HeadlineStems::ofWords(HeadlineStems::words($stem)), $stem);
        }
    }

    /** @return array<string, string> */
    private static function table(string $name): array
    {
        $table = new \ReflectionClassConstant(HeadlineEquivalents::class, $name)->getValue();
        self::assertIsArray($table);

        /** @var array<string, string> $table */
        return $table;
    }
}
