<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class CasefoldedWords
{
    private const string WORD_SEPARATOR = '/[^\p{L}\p{M}\p{N}]+/u';

    /** @return list<string> casefolded, diacritics kept */
    public static function splitWords(string $text): array
    {
        $composed = CaseFold::normalized($text, \Normalizer::FORM_KC);

        return preg_split(self::WORD_SEPARATOR, $composed, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<array{string, string}> each word casefolded, then as it stands in the text */
    public static function collectWordsWithSurfaces(string $text): array
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $composed = \is_string($normalized) ? $normalized : $text;
        $surfaces = preg_split(self::WORD_SEPARATOR, $composed, -1, \PREG_SPLIT_NO_EMPTY);

        return array_map(
            static fn (string $surface): array => [CaseFold::normalized($surface, \Normalizer::FORM_KC), $surface],
            $surfaces ?: [],
        );
    }

    private function __construct()
    {
    }
}
