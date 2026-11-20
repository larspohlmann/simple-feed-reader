<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Support\HeadlineStems;
use App\Service\Foundation\Text\Support\CasefoldedWords;

/** Spellings of one institution share one stem in all four languages; never a person, event or country (#2231). */
final readonly class HeadlineEquivalents
{
    /** Case-sensitive whole words: abbreviations. */
    private const array EXACT_WORDS = [
        'EU' => 'eunion', 'UE' => 'eunion',
        'UN' => 'onu', 'UNO' => 'onu', 'ONU' => 'onu',
    ];

    /** Exact words that are also ordinary words in a shouted headline: French "UN PLAN", Spanish "UNO MÁS". */
    private const array SHOUTABLE_WORDS = ['UN' => true, 'UNO' => true];

    /** Folded words without diacritics, joined by one space. */
    private const array PHRASES = [
        'united nations' => 'onu', 'vereinte nationen' => 'onu', 'nations unies' => 'onu',
        'naciones unidas' => 'onu', 'vereinten nationen' => 'onu',
        'europaische union' => 'eunion', 'european union' => 'eunion', 'union europeenne' => 'eunion',
        'union europea' => 'eunion', 'europaischen union' => 'eunion',
    ];

    /** A key also merges every unrelated word sharing its six letters. */
    private const array CUT_STEMS = [
        'otan' => 'nato',
    ];

    /** Keyed by the language's Snowball stem without diacritics: a key merges every word of the language stemming to it. */
    private const array STEMS_BY_LANGUAGE = [
        'de' => [],
        'en' => [],
        'fr' => [
            'otan' => 'nato',
        ],
        'es' => [
            'otan' => 'nato',
        ],
    ];

    private string $exactWordPattern;

    /** @var array<string, list<array{list<string>, string}>> first word => phrase words and canonical */
    private array $phrasesByFirstWord;

    /** @var array<string, true> */
    private array $canonicals;

    public function __construct(private HeadlineStemmer $stemmer, private GermanCompoundSplitter $splitter)
    {
        $this->exactWordPattern = self::exactWordPattern();
        $this->phrasesByFirstWord = self::phrasesByFirstWord();
        $this->canonicals = self::canonicals();
    }

    /** @return list<array{string, string}> each kept word or phrase canonical, with its bare form */
    public function canonicalizeWords(string $text): array
    {
        return array_values(array_filter(
            $this->collapsePhrases(CasefoldedWords::splitWords($this->replaceExactWords($text))),
            static fn (array $word): bool => HeadlineStems::isKept($word[1]),
        ));
    }

    /** @return list<string> distinct stems: the whole word's, then those of a German compound's parts */
    public function stemWordAndParts(string $word, string $bare, ?FeedLanguage $language): array
    {
        $stems = [$this->stemWord($word, $bare, $language)];
        if ($language === FeedLanguage::German && !isset($this->canonicals[$bare])) {
            foreach ($this->splitter->splitParts($word) as $part) {
                $stems[] = $this->stemWord($part, HeadlineStems::bare($part), $language);
            }
        }

        return array_values(array_unique($stems));
    }

    /** A canonical is a stem already: stemming it in a language would split it from the others. */
    private function stemWord(string $word, string $bare, ?FeedLanguage $language): string
    {
        if (isset($this->canonicals[$bare])) {
            return $bare;
        }
        $stem = $this->stemmer->stem($word, $language);

        return self::stemsFor($language)[$stem] ?? $stem;
    }

    /** @return array<string, string> */
    private static function stemsFor(?FeedLanguage $language): array
    {
        return $language === null ? self::CUT_STEMS : self::STEMS_BY_LANGUAGE[$language->value];
    }

    private function replaceExactWords(string $text): string
    {
        preg_match_all($this->exactWordPattern, $text, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        $replaced = $text;
        foreach (array_reverse($matches) as [[$word, $offset]]) {
            if (isset(self::SHOUTABLE_WORDS[$word]) && self::isInAllCapsRun($text, $offset, \strlen($word))) {
                continue;
            }
            $replaced = substr_replace($replaced, self::EXACT_WORDS[$word], $offset, \strlen($word));
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

        return $neighbours !== [] && array_all($neighbours, self::isAllCaps(...));
    }

    private static function isAllCaps(string $word): bool
    {
        return mb_strtoupper($word) === $word && preg_match_all('/\p{Lu}/u', $word) >= 2;
    }

    /**
     * @param list<string> $words
     *
     * @return list<array{string, string}> each word or phrase canonical, with its bare form
     */
    private function collapsePhrases(array $words): array
    {
        $bareWords = array_map(HeadlineStems::bare(...), $words);
        $collapsed = [];
        $index = 0;
        while ($index < \count($words)) {
            [$length, $canonical] = $this->phraseAt($bareWords, $index);
            $collapsed[] = $canonical === null ? [$words[$index], $bareWords[$index]] : [$canonical, $canonical];
            $index += $length;
        }

        return $collapsed;
    }

    /**
     * @param list<string> $bareWords
     *
     * @return array{int, ?string} how many words it covers, and the phrase's canonical if one starts here
     */
    private function phraseAt(array $bareWords, int $index): array
    {
        foreach ($this->phrasesByFirstWord[$bareWords[$index]] ?? [] as [$phraseWords, $canonical]) {
            if (\array_slice($bareWords, $index, \count($phraseWords)) === $phraseWords) {
                return [\count($phraseWords), $canonical];
            }
        }

        return [1, null];
    }

    private static function exactWordPattern(): string
    {
        return '/(?<![\p{L}\p{N}])(?:' . implode('|', array_keys(self::EXACT_WORDS)) . ')(?![\p{L}\p{N}])/u';
    }

    /** @return array<string, true> */
    private static function canonicals(): array
    {
        $canonicals = [];
        foreach ([self::EXACT_WORDS, self::PHRASES, self::CUT_STEMS, ...self::STEMS_BY_LANGUAGE] as $table) {
            $canonicals += array_fill_keys($table, true);
        }

        return $canonicals;
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
