<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\Support\HeadlineStems;

/** Spellings of one thing, across the four app languages, share one stem (#2225); every entry is reviewed by hand. */
final readonly class HeadlineEquivalents
{
    /** Case-sensitive whole words: abbreviations, and long forms whose six-letter stem would catch unrelated words. */
    private const array EXACT_WORDS = [
        'EU' => 'eunion', 'UE' => 'eunion',
        'ONU' => 'onu',
        'UK' => 'britai', 'Großbritannien' => 'britai', 'Großbritanniens' => 'britai',
        'WM' => 'weltme',
        'Schweiz' => 'switze', 'Schweizer' => 'switze',
        'Austria' => 'osterr', 'Austrian' => 'osterr',
        'Niederlande' => 'nether', 'Niederlanden' => 'nether',
        'Francia' => 'france',
    ];

    /** Exact words that are also ordinary words in a shouted headline: French "UN PLAN", Spanish "UNO MÁS". */
    private const array SHOUTABLE_WORDS = [
        'UN' => 'onu', 'UNO' => 'onu',
    ];

    /** Folded words joined by one space. */
    private const array PHRASES = [
        'united nations' => 'onu', 'vereinte nationen' => 'onu', 'nations unies' => 'onu',
        'naciones unidas' => 'onu', 'vereinten nationen' => 'onu',
        'europaische union' => 'eunion', 'european union' => 'eunion', 'union europeenne' => 'eunion',
        'union europea' => 'eunion', 'europaischen union' => 'eunion',
        'united kingdom' => 'britai', 'royaume uni' => 'britai', 'reino unido' => 'britai',
        'pays bas' => 'nether', 'paises bajos' => 'nether',
    ];

    /** Cut stems; checked against the dev windows for unrelated words sharing the six letters. */
    private const array STEMS = [
        'zelens' => 'selens',
        'russla' => 'russia', 'russie' => 'russia', 'rusia' => 'russia',
        'ucrani' => 'ukrain',
        'chine' => 'china', 'chinas' => 'china',
        'frankr' => 'france',
        'spanie' => 'spain', 'espagn' => 'spain', 'espana' => 'spain',
        'italie' => 'italia', 'italy' => 'italia',
        'turkei' => 'turkey', 'turqui' => 'turkey',
        'japon' => 'japan', 'japans' => 'japan',
        'polen' => 'poland', 'polens' => 'poland', 'pologn' => 'poland', 'poloni' => 'poland',
        'suisse' => 'switze', 'suiza' => 'switze',
        'autric' => 'osterr',
        'brasil' => 'brazil', 'bresil' => 'brazil',
        'mexiko' => 'mexico', 'mexiqu' => 'mexico',
        'indien' => 'india', 'inde' => 'india',
        'otan' => 'nato',
        'syrien' => 'syria', 'syrie' => 'syria', 'siria' => 'syria',
        'moskau' => 'moscow', 'moscou' => 'moscow', 'moscu' => 'moscow',
        'peking' => 'beijin', 'pekin' => 'beijin',
        'kreml' => 'kremli', 'kremls' => 'kremli',
        'brusel' => 'brusse', 'bruxel' => 'brusse',
    ];

    private string $exactWordPattern;

    /** @var array<string, list<array{list<string>, string}>> first word => phrase words and canonical */
    private array $phrasesByFirstWord;

    public function __construct()
    {
        $this->exactWordPattern = self::exactWordPattern();
        $this->phrasesByFirstWord = self::phrasesByFirstWord();
    }

    /** @return list<string> distinct stems */
    public function stemsOf(string $text): array
    {
        $stems = HeadlineStems::ofWords($this->collapsePhrases(HeadlineStems::words($this->replaceExactWords($text))));

        return array_values(array_unique(array_map(
            static fn (string $stem): string => self::STEMS[$stem] ?? $stem,
            $stems,
        )));
    }

    private function replaceExactWords(string $text): string
    {
        preg_match_all($this->exactWordPattern, $text, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        $replaced = $text;
        foreach (array_reverse($matches) as [[$word, $offset]]) {
            if (isset(self::SHOUTABLE_WORDS[$word]) && self::isInAllCapsRun($text, $offset, \strlen($word))) {
                continue;
            }
            $canonical = self::EXACT_WORDS[$word] ?? self::SHOUTABLE_WORDS[$word];
            $replaced = substr_replace($replaced, $canonical, $offset, \strlen($word));
        }

        return $replaced;
    }

    /** Every word next to it is all-caps: the abbreviation stands in a shouted run (LET US GO), not in a sentence. */
    private static function isInAllCapsRun(string $text, int $offset, int $length): bool
    {
        $neighbours = [];
        if (preg_match('/[\p{L}\p{N}]+(?=[^\p{L}\p{N}]*$)/u', substr($text, 0, $offset), $previous) === 1) {
            $neighbours[] = $previous[0];
        }
        if (preg_match('/^[^\p{L}\p{N}]*\K[\p{L}\p{N}]+/u', substr($text, $offset + $length), $next) === 1) {
            $neighbours[] = $next[0];
        }

        return $neighbours !== [] && array_filter($neighbours, self::isAllCaps(...)) === $neighbours;
    }

    private static function isAllCaps(string $word): bool
    {
        return mb_strtoupper($word) === $word && preg_match_all('/\p{Lu}/u', $word) >= 2;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private function collapsePhrases(array $words): array
    {
        $collapsed = [];
        $index = 0;
        while ($index < \count($words)) {
            [$length, $word] = $this->wordOrPhraseAt($words, $index);
            $collapsed[] = $word;
            $index += $length;
        }

        return $collapsed;
    }

    /**
     * @param list<string> $words
     *
     * @return array{int, string} how many words it covers, and the word or the phrase's canonical
     */
    private function wordOrPhraseAt(array $words, int $index): array
    {
        foreach ($this->phrasesByFirstWord[$words[$index]] ?? [] as [$phraseWords, $canonical]) {
            if (\array_slice($words, $index, \count($phraseWords)) === $phraseWords) {
                return [\count($phraseWords), $canonical];
            }
        }

        return [1, $words[$index]];
    }

    private static function exactWordPattern(): string
    {
        return '/(?<![\p{L}\p{N}])(?:' . implode('|', array_keys([...self::EXACT_WORDS, ...self::SHOUTABLE_WORDS])) . ')(?![\p{L}\p{N}])/u';
    }

    /** @return array<string, list<array{list<string>, string}>> */
    private static function phrasesByFirstWord(): array
    {
        $index = [];
        foreach (self::PHRASES as $phrase => $canonical) {
            $phraseWords = explode(' ', $phrase);
            $index[$phraseWords[0]][] = [$phraseWords, $canonical];
        }

        return $index;
    }
}
