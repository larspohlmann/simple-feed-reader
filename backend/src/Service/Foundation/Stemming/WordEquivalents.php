<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Text\Support\CasefoldedWords;

/** Spellings of one institution share one stem in all four languages; never a person, event or country (#2231). */
final readonly class WordEquivalents
{
    /** Case-sensitive whole words: abbreviations. */
    private const array EXACT_WORDS = [
        'EU' => 'eunion', 'UE' => 'eunion',
        'UN' => 'onu', 'UNO' => 'onu', 'ONU' => 'onu',
    ];

    /** Exact words that are also ordinary words in shouted text: French "UN PLAN", Spanish "UNO MÁS". */
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

    /** @var array<string, list<array{list<string>, string}>> first word => phrase words and canonical */
    private array $phrasesByFirstWord;

    /** @var array<string, true> */
    private array $canonicals;

    public function __construct(private WordStemmer $stemmer, private GermanCompoundSplitter $splitter)
    {
        $this->phrasesByFirstWord = self::phrasesByFirstWord();
        $this->canonicals = self::canonicals();
    }

    /** @return list<array{string, string}> each kept word or phrase canonical, with its bare form */
    public function canonicalizeWords(string $text): array
    {
        return array_map(
            static fn (array $word): array => [$word[0], $word[1]],
            $this->canonicalizeWordsWithSurfaces($text),
        );
    }

    /** @return list<array{string, string, string}> each kept word or phrase canonical, its bare form, and its spelling */
    public function canonicalizeWordsWithSurfaces(string $text): array
    {
        return array_values(array_filter(
            $this->collapsePhrases($this->replaceExactWords(CasefoldedWords::collectWordsWithSurfaces($text))),
            static fn (array $word): bool => WordStems::isKept($word[1]),
        ));
    }

    /** @return list<string> distinct stems: the whole word's, then those of a German compound's parts */
    public function stemWordAndParts(string $word, string $bare, ?FeedLanguage $language): array
    {
        $stems = [$this->stemWord($word, $bare, $language)];
        if ($language === FeedLanguage::German && !isset($this->canonicals[$bare])) {
            foreach ($this->splitter->splitParts($word) as $part) {
                $stems[] = $this->stemWord($part, WordStems::bare($part), $language);
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

        return self::readStems($language)[$stem] ?? $stem;
    }

    /** @return array<string, string> */
    private static function readStems(?FeedLanguage $language): array
    {
        return $language === null ? self::CUT_STEMS : self::STEMS_BY_LANGUAGE[$language->value];
    }

    /**
     * @param list<array{string, string}> $words each casefolded, with its spelling
     *
     * @return list<array{string, string}>
     */
    private function replaceExactWords(array $words): array
    {
        $replaced = [];
        foreach ($words as $index => [$folded, $surface]) {
            $isExact = isset(self::EXACT_WORDS[$surface])
                && !(isset(self::SHOUTABLE_WORDS[$surface]) && self::isInAllCapsRun($words, $index));
            $replaced[] = [$isExact ? self::EXACT_WORDS[$surface] : $folded, $surface];
        }

        return $replaced;
    }

    /**
     * Every word next to it is all-caps: the abbreviation stands in a shouted run (LET US GO), not in a sentence.
     *
     * @param list<array{string, string}> $words
     */
    private static function isInAllCapsRun(array $words, int $index): bool
    {
        $neighbours = array_column(
            array_filter([$words[$index - 1] ?? null, $words[$index + 1] ?? null]),
            1,
        );

        return $neighbours !== [] && array_all($neighbours, self::isAllCaps(...));
    }

    private static function isAllCaps(string $word): bool
    {
        return mb_strtoupper($word) === $word && preg_match_all('/\p{Lu}/u', $word) >= 2;
    }

    /**
     * @param list<array{string, string}> $words each casefolded, with its spelling
     *
     * @return list<array{string, string, string}> each word or phrase canonical, with its bare form and spelling
     */
    private function collapsePhrases(array $words): array
    {
        $bareWords = array_map(static fn (array $word): string => WordStems::bare($word[0]), $words);
        $collapsed = [];
        $index = 0;
        while ($index < \count($words)) {
            [$length, $canonical] = $this->matchPhrase($bareWords, $index);
            $spelling = implode(' ', array_column(\array_slice($words, $index, $length), 1));
            $collapsed[] = $canonical === null
                ? [$words[$index][0], $bareWords[$index], $spelling]
                : [$canonical, $canonical, $spelling];
            $index += $length;
        }

        return $collapsed;
    }

    /**
     * @param list<string> $bareWords
     *
     * @return array{int, ?string} how many words it covers, and the phrase's canonical if one starts here
     */
    private function matchPhrase(array $bareWords, int $index): array
    {
        foreach ($this->phrasesByFirstWord[$bareWords[$index]] ?? [] as [$phraseWords, $canonical]) {
            if (\array_slice($bareWords, $index, \count($phraseWords)) === $phraseWords) {
                return [\count($phraseWords), $canonical];
            }
        }

        return [1, null];
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
