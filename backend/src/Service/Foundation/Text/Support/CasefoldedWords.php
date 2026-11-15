<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class CasefoldedWords
{
    /** @return list<string> casefolded, diacritics kept: the Snowball stemmers read them */
    public static function of(string $text): array
    {
        $folded = mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8');
        $composed = \Normalizer::normalize($folded, \Normalizer::FORM_KC);
        $source = \is_string($composed) ? $composed : $folded;

        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', $source, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function __construct()
    {
    }
}
