<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\Support\HeadlineStems;

/** Spellings of one thing, across the four app languages, share one stem (#2225); every entry is reviewed by hand. */
final readonly class HeadlineEquivalents
{
    /** Case-sensitive whole words: abbreviations, and long forms whose six-letter stem would catch unrelated words. */
    private const array EXACT_WORDS = [
        'US' => 'usa', 'USA' => 'usa', 'EEUU' => 'usa',
        'EU' => 'europa', 'UE' => 'europa',
        'UN' => 'onu', 'UNO' => 'onu', 'ONU' => 'onu',
        'WM' => 'weltme',
        'U-Haft' => 'uhaft', 'Untersuchungshaft' => 'uhaft',
    ];

    /** Folded words joined by one space. */
    private const array PHRASES = [
        'united states' => 'usa', 'vereinigte staaten' => 'usa', 'etats unis' => 'usa',
        'estados unidos' => 'usa', 'ee uu' => 'usa',
        'united nations' => 'onu', 'vereinte nationen' => 'onu', 'nations unies' => 'onu',
        'naciones unidas' => 'onu',
        'europaische union' => 'europa', 'european union' => 'europa', 'union europeenne' => 'europa',
        'union europea' => 'europa',
    ];

    private const array STEMS = [
        'europe' => 'europa',
        'german' => 'deutsc', 'allema' => 'deutsc', 'aleman' => 'deutsc',
        'zelens' => 'selens',
    ];

    private string $exactWordPattern;

    /** @var array<string, list<array{list<string>, string}>> first word => phrase words and canonical, longest first */
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

    /** All-caps text makes common words look like abbreviations (LET US GO), so it is left alone. */
    private function replaceExactWords(string $text): string
    {
        if (preg_match('/\p{Ll}/u', $text) !== 1) {
            return $text;
        }

        preg_match_all($this->exactWordPattern, $text, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        $replaced = $text;
        foreach (array_reverse($matches) as [[$word, $offset]]) {
            if (!self::isBetweenAllCapsWords($text, $offset, \strlen($word))) {
                $replaced = substr_replace($replaced, ' ' . self::EXACT_WORDS[$word] . ' ', $offset, \strlen($word));
            }
        }

        return $replaced;
    }

    private static function isBetweenAllCapsWords(string $text, int $offset, int $length): bool
    {
        $before = preg_match('/([\p{L}\p{N}]+)[^\p{L}\p{N}]*$/u', substr($text, 0, $offset), $previous) === 1;
        $after = preg_match('/^[^\p{L}\p{N}]*([\p{L}\p{N}]+)/u', substr($text, $offset + $length), $next) === 1;

        return ($before && self::isAllCaps($previous[1])) || ($after && self::isAllCaps($next[1]));
    }

    private static function isAllCaps(string $word): bool
    {
        return preg_match('/\p{Ll}/u', $word) !== 1 && preg_match_all('/\p{Lu}/u', $word) >= 2;
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
        $words = array_keys(self::EXACT_WORDS);
        usort($words, static fn (string $left, string $right): int => \strlen($right) <=> \strlen($left));
        $alternatives = implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $words));

        return '/(?<![\p{L}\p{N}-])(?:' . $alternatives . ')(?![\p{L}\p{N}-])/u';
    }

    /** @return array<string, list<array{list<string>, string}>> */
    private static function phrasesByFirstWord(): array
    {
        $index = [];
        foreach (self::PHRASES as $phrase => $canonical) {
            $phraseWords = explode(' ', $phrase);
            $index[$phraseWords[0]][] = [$phraseWords, $canonical];
        }
        foreach ($index as &$phrases) {
            usort($phrases, static fn (array $left, array $right): int => \count($right[0]) <=> \count($left[0]));
        }

        return $index;
    }
}
