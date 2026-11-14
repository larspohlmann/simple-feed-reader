<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

final class HeadlineStems
{
    private const int STEM_LENGTH = 6;
    private const int MIN_WORD_LENGTH = 3;
    private const int MIN_NUMBER_LENGTH = 2;

    /** @return list<string> the distinct stems, in reading order */
    public static function of(string $text): array
    {
        $stems = [];
        foreach (self::words($text) as $word) {
            if (self::isKept($word)) {
                $stems[] = mb_substr($word, 0, self::STEM_LENGTH);
            }
        }

        return array_values(array_unique($stems));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $folded = mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8');
        $decomposed = \Normalizer::normalize($folded, \Normalizer::FORM_KD);
        $bare = preg_replace('/\p{Mn}+/u', '', \is_string($decomposed) ? $decomposed : $folded) ?? $folded;

        return preg_split('/[^\p{L}\p{N}]+/u', $bare, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function isKept(string $word): bool
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
