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
        foreach (['Germany', 'Deutschland', 'Allemagne', 'Alemania'] as $name) {
            self::assertSame(['deutsc'], $this->equivalents->stemsOf($name), $name);
        }
    }

    public function testAPhraseIsConsumedIntoOneStem(): void
    {
        self::assertEqualsCanonicalizing(['usa', 'tariff'], $this->equivalents->stemsOf('United States tariffs'));
    }

    public function testPhrasesMatchAcrossPunctuationAndCase(): void
    {
        foreach (['États-Unis', 'ETATS UNIS et Chine'] as $text) {
            $stems = $this->equivalents->stemsOf($text);
            self::assertContains('usa', $stems, $text);
            self::assertNotContains('etats', $stems, $text);
            self::assertNotContains('unis', $stems, $text);
        }
    }

    public function testARepeatedPhraseCountsOnce(): void
    {
        self::assertEqualsCanonicalizing(
            ['usa', 'und'],
            $this->equivalents->stemsOf('Vereinigte Staaten und Vereinigte Staaten'),
        );
    }

    public function testTheSpanishAbbreviationWithDotsIsAPhrase(): void
    {
        self::assertContains('usa', $this->equivalents->stemsOf('Los EE. UU. anuncian aranceles'));
    }

    public function testAShortAbbreviationIsKeptAndMapped(): void
    {
        self::assertEqualsCanonicalizing(['europa', 'ban'], $this->equivalents->stemsOf('EU to ban AI'));
    }

    public function testALowerCaseWordIsNotAnAbbreviation(): void
    {
        self::assertNotContains('usa', $this->equivalents->stemsOf('Tell us more'));
    }

    #[DataProvider('allCapsTexts')]
    public function testAnAbbreviationInAllCapsTextDoesNotFire(string $text): void
    {
        $stems = $this->equivalents->stemsOf($text);

        self::assertNotContains('usa', $stems);
        self::assertNotContains('europa', $stems);
    }

    /** @return iterable<string, array{string}> */
    public static function allCapsTexts(): iterable
    {
        yield 'whole text in capitals' => ['LET US GO'];
        yield 'the abbreviation alone' => ['EU'];
        yield 'next to an all-caps word' => ['Breaking: WHO US NEWS today'];
    }

    public function testAnAbbreviationAtTheEdgesOfMixedTextFires(): void
    {
        self::assertContains('usa', $this->equivalents->stemsOf('US imposes tariffs'));
        self::assertContains('europa', $this->equivalents->stemsOf('Tariffs hit the EU'));
    }

    public function testAHyphenatedAbbreviationMatchesOnlyAsAWholeWord(): void
    {
        self::assertContains('uhaft', $this->equivalents->stemsOf('Richter verhängt U-Haft'));
        self::assertNotContains('uhaft', $this->equivalents->stemsOf('Der U-Haft-Antrag scheitert'));
    }

    public function testALongFormWhoseStemCollidesIsAnExactWord(): void
    {
        $stems = $this->equivalents->stemsOf('Wir unterstützen die Untersuchungshaft');

        self::assertContains('uhaft', $stems);
        self::assertContains('unters', $stems);
        self::assertNotContains('uhaft', $this->equivalents->stemsOf('Wir unterstützen'));
    }

    public function testUnlistedWordsAreStemmedAsBefore(): void
    {
        self::assertSame(['ukrain', 'talks'], $this->equivalents->stemsOf('Ukraine Ukrainian talks'));
    }

    public function testEveryCanonicalStemIsInStemForm(): void
    {
        $filler = new HeadlineFiller();
        $stemTable = self::table('STEMS');
        foreach (['EXACT_WORDS', 'PHRASES', 'STEMS'] as $table) {
            foreach (self::table($table) as $key => $canonical) {
                $label = \sprintf('%s[%s] = %s', $table, $key, $canonical);
                self::assertGreaterThanOrEqual(3, mb_strlen($canonical), $label);
                self::assertSame([$canonical], HeadlineStems::ofWords(HeadlineStems::words($canonical)), $label);
                self::assertFalse($filler->isSeed($canonical), $label);
                self::assertArrayNotHasKey($canonical, $stemTable, $label);
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
