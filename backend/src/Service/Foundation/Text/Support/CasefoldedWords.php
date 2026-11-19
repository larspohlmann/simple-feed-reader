<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class CasefoldedWords
{
    /** @return list<string> casefolded, diacritics kept */
    public static function splitWords(string $text): array
    {
        $composed = CaseFold::normalized($text, \Normalizer::FORM_KC);

        return preg_split('/[^\p{L}\p{M}\p{N}]+/u', $composed, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function __construct()
    {
    }
}
