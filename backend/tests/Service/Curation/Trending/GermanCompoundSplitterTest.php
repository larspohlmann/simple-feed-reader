<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Trending\GermanCompoundSplitter;
use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GermanCompoundSplitterTest extends TestCase
{
    private const string FIXTURE_LEXICON = __DIR__ . '/../../../Fixtures/german-lexicon';
    private const string BUNDLED_LEXICON = __DIR__ . '/../../../../resources/german-lexicon';

    /** @param list<string> $expectedParts */
    #[DataProvider('fixtureCompounds')]
    public function testSplitsAWordIntoItsLexiconParts(string $word, array $expectedParts): void
    {
        $splitter = new GermanCompoundSplitter(new BundledGermanLexicon(self::FIXTURE_LEXICON));

        $this->assertSame($expectedParts, $splitter->partsOf($word));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function fixtureCompounds(): iterable
    {
        yield 'two nouns' => ['polizeieinsatz', ['polizei', 'einsatz']];
        yield 'an inflected head' => ['polizeieinsätze', ['polizei', 'einsätze']];
        yield 'a modifier ending in -er' => ['kinderarzt', ['kinder', 'arzt']];
        yield 'the -es- link' => ['bundesrat', ['bund', 'rat']];
        yield 'the -s- link' => ['arbeitsamt', ['arbeit', 'amt']];
        yield 'the restored -e' => ['schulbus', ['schule', 'bus']];
        yield 'three parts' => ['kinderarztpraxis', ['kinder', 'arzt', 'praxis']];
        yield 'fewer parts before a lower mean' => ['bundesrathaus', ['bund', 'rathaus']];
        yield 'an equal mean goes to the longer head' => ['staubecken', ['stau', 'becken']];
        yield 'a known whole rarer than its parts' => ['rathaus', ['rat', 'haus']];
        yield 'a rare long part' => ['spionageverdacht', ['spionage', 'verdacht']];
        yield 'a rare head' => ['kinderinstinkt', ['kinder', 'instinkt']];
        yield 'a modifier variant tie goes to the shorter form' => ['tierarzt', ['tier', 'arzt']];
        yield 'a known compound rarer than both parts' => ['rathaus', ['rat', 'haus']];
        yield 'exactly the most parts' => ['tagtagtagtag', ['tag', 'tag', 'tag', 'tag']];
        yield 'a known whole as frequent as its parts' => ['bundesamt', []];
        yield 'a known whole with a part rarer than itself' => ['busion', []];
        yield 'a modifier that is no noun' => ['einsatz', []];
        yield 'a known whole more frequent than its parts' => ['aktion', []];
        yield 'a head that is no noun' => ['hausgroß', []];
        yield 'an unknown modifier' => ['xyzhaus', []];
        yield 'a modifier without a vowel' => ['pkwhaus', []];
        yield 'shorter than two parts' => ['tagra', []];
        yield 'no split into known parts' => ['polizei', []];
        yield 'one part more than the most parts' => ['tagtagtagtagtag', []];
    }

    /** @param list<string> $expectedParts */
    #[DataProvider('issueCompounds')]
    public function testSplitsTheIssueExamplesWithTheBundledLexicon(string $word, array $expectedParts): void
    {
        $splitter = new GermanCompoundSplitter(new BundledGermanLexicon(self::BUNDLED_LEXICON));

        $this->assertSame($expectedParts, $splitter->partsOf($word));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function issueCompounds(): iterable
    {
        yield 'Spionageverdacht' => ['spionageverdacht', ['spionage', 'verdacht']];
        yield 'Polizeieinsatz' => ['polizeieinsatz', ['polizei', 'einsatz']];
        yield 'Cyberangriff' => ['cyberangriff', ['cyber', 'angriff']];
        yield 'Dreiergipfel' => ['dreiergipfel', []];
        yield 'Sonntagmittag' => ['sonntagmittag', ['sonntag', 'mittag']];
        yield 'Bundestag' => ['bundestag', ['bund', 'tag']];
        yield 'Einsatz' => ['einsatz', []];
        yield 'Kinderarztpraxis' => ['kinderarztpraxis', ['kind', 'arztpraxis']];
        yield 'Ministerpräsident' => ['ministerpräsident', ['minister', 'präsident']];
        yield 'Bundesrat' => ['bundesrat', ['bund', 'rat']];
        yield 'Sonntagvormittag' => ['sonntagvormittag', ['sonntag', 'vormittag']];
        yield 'Salami' => ['salami', ['sale', 'ami']];
        yield 'Tierarzt' => ['tierarzt', ['tier', 'arzt']];
        yield 'Sternhimmel' => ['sternhimmel', ['stern', 'himmel']];
        yield 'Fischmarkt' => ['fischmarkt', ['fisch', 'markt']];
        yield 'Weihnachtsbaum' => ['weihnachtsbaum', []];
        yield 'Aktenbestand' => ['aktenbestand', ['akt', 'bes', 'tand']];
    }
}
