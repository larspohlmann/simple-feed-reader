<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Support;

use App\Service\Foundation\Text\Support\CaseFold;

final class WordStems
{
    private const int CUT_LENGTH = 6;
    private const int MIN_WORD_LENGTH = 3;
    private const int MIN_NUMBER_LENGTH = 2;

    /** Casefolded, without diacritics. */
    public static function bare(string $word): string
    {
        $decomposed = CaseFold::normalized($word, \Normalizer::FORM_KD);

        return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
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
