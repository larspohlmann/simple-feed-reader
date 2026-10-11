<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class WordBoundary
{
    private const string ELLIPSIS = '…';
    private const string DANGLING_PUNCTUATION = ' ,;:-–—';

    /** The text, or its longest head of at most $maxLength characters that ends before a space. */
    public static function cut(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $head = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($head, ' ');

        return $lastSpace === false ? $head : mb_substr($head, 0, $lastSpace);
    }

    /** The text, or its head cut before a space and closed by an ellipsis, at most $maxLength characters in all. */
    public static function clipped(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(self::cut($text, $maxLength - 1), self::DANGLING_PUNCTUATION) . self::ELLIPSIS;
    }

    private function __construct()
    {
    }
}
