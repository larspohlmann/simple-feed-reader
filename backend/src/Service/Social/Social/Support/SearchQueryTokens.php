<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

final class SearchQueryTokens
{
    public const string OR = 'or';
    public const string OPEN = 'open';
    public const string CLOSE = 'close';
    public const string PHRASE = 'phrase';
    public const string NOT = 'not';
    public const string WORD = 'word';
    private const string WORD_PATTERN = '/[\p{L}\p{N}]+/u';
    private const string FILTER_PATTERN = '/^\p{L}+:./u';
    private const string PATTERN = '/(?<or>\|\|)|(?<open>\()|(?<close>\))|"(?<phrase>[^"]*)"?'
        . '|(?<not>-)(?=[^\s|)])|(?<word>[^\s()"|]+)|\S/u';

    /** @return list<array{string, string}> kind and text */
    public static function tokenizeQuery(string $query): array
    {
        preg_match_all(self::PATTERN, $query, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $tokens = [];
        foreach ($matches as $match) {
            foreach ([self::OR, self::OPEN, self::CLOSE, self::PHRASE, self::NOT, self::WORD] as $kind) {
                if (null !== ($match[$kind] ?? null)) {
                    $tokens[] = [$kind, $match[$kind]];
                    break;
                }
            }
        }

        return $tokens;
    }

    public static function isFilter(string $word): bool
    {
        return 1 === preg_match(self::FILTER_PATTERN, $word);
    }

    public static function isAuthorFilter(string $word): bool
    {
        return str_starts_with(mb_strtolower($word), 'from:');
    }

    /** @return list<string> the lower-cased letter and digit runs of a text, as the matcher reads it */
    public static function splitWords(string $text): array
    {
        preg_match_all(self::WORD_PATTERN, mb_strtolower($text), $words);

        return $words[0];
    }

    private function __construct()
    {
    }
}
