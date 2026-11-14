<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

final class HeadlineStems
{
    private const int CUT_LENGTH = 6;
    private const int MIN_WORD_LENGTH = 3;
    private const int MIN_NUMBER_LENGTH = 2;

    /** @return list<string> casefolded, diacritics kept: the Snowball stemmers read them */
    public static function words(string $text): array
    {
        $folded = mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8');
        $composed = \Normalizer::normalize($folded, \Normalizer::FORM_KC);
        $source = \is_string($composed) ? $composed : $folded;

        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', $source, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Casefolded, without diacritics. */
    public static function bare(string $word): string
    {
        $folded = mb_convert_case($word, \MB_CASE_FOLD, 'UTF-8');
        $decomposed = \Normalizer::normalize($folded, \Normalizer::FORM_KD);

        return preg_replace('/\p{Mn}+/u', '', \is_string($decomposed) ? $decomposed : $folded) ?? $folded;
    }

    /** The stem of a word whose language is unknown. */
    public static function cut(string $word): string
    {
        return mb_substr(self::bare($word), 0, self::CUT_LENGTH);
    }

    public static function isKept(string $word): bool
    {
        if (ctype_digit($word)) {
            return \strlen($word) >= self::MIN_NUMBER_LENGTH;
        }

        return mb_strlen($word) >= self::MIN_WORD_LENGTH;
    }

    private function __construct()
    {
    }
}
