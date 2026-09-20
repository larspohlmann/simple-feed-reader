<?php

declare(strict_types=1);

namespace App\Service\Text;

/**
 * A plain-text preview for an entry list row: the summary's text, or the
 * body's, cut to the first MAX_LENGTH characters at a word boundary.
 */
final class EntryExcerpt
{
    private const int MAX_LENGTH = 500;

    public static function of(?string $summary, ?string $contentHtml): string
    {
        return self::plainText($summary) ?? self::plainText($contentHtml) ?? '';
    }

    private static function plainText(?string $html): ?string
    {
        $text = EntryPlainText::of($html);

        return $text === null ? null : self::cutAtWordBoundary($text);
    }

    private static function cutAtWordBoundary(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');

        return $lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace);
    }
}
