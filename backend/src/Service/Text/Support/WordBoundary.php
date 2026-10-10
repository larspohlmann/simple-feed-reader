<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class WordBoundary
{
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

    private function __construct()
    {
    }
}
