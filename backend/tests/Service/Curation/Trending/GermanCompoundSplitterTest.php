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
        yield 'an equal mean goes to the longer head' => ['staubecken', ['stau', 'becken']];
        yield 'a rare long part no rarer than the median noun of its length' => [
            'spionageverdacht',
            ['spionage', 'verdacht'],
        ];
        yield 'exactly the most parts' => ['tagtagtagtag', ['tag', 'tag', 'tag', 'tag']];
        yield 'rarer parts than the unsplit word' => ['aktion', []];
        yield 'an unsplit word as frequent as its parts' => ['amtsarzt', []];
        yield 'a modifier under three letters and a head that is no noun' => ['urinstinkt', []];
        yield 'a head that is no noun' => ['hausgroß', []];
        yield 'an unknown modifier' => ['xyzhaus', []];
        yield 'a modifier rarer than the median noun of its length' => ['aabhaus', []];
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
        yield 'Spionageverdacht' => ['spionageverdacht', ['spion', 'age', 'verdacht']];
        yield 'Polizeieinsatz' => ['polizeieinsatz', ['polizei', 'eine', 'satz']];
        yield 'Cyberangriff' => ['cyberangriff', ['cyber', 'angriff']];
        yield 'Dreiergipfel' => ['dreiergipfel', ['drei', 'gipfel']];
        yield 'Sonntagmittag' => ['sonntagmittag', ['sonne', 'tage', 'mit', 'tag']];
    }
}
