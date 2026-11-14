<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

final class SearchQueryTokens
{
    public const string OR = 'or';
    public const string OPEN = 'open';
    public const string CLOSE = 'close';
    public const string PHRASE = 'phrase';
    public const string NOT = 'not';
    public const string WORD = 'word';
    private const string PATTERN = '/(?<or>\|\|)|(?<open>\()|(?<close>\))|"(?<phrase>[^"]*)"?'
        . '|(?<not>-)(?=[^\s|)])|(?<word>[^\s()"|]+)|\S/u';

    /** @return list<array{string, string}> kind and text */
    public static function of(string $query): array
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

    private function __construct()
    {
    }
}
