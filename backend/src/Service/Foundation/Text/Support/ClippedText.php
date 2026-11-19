<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class ClippedText
{
    /** Clips by character, not byte: a text cut mid-umlaut is invalid UTF-8, which json_encode rejects. */
    public static function cutText(string $text, int $characters, string $marker = '…'): string
    {
        return mb_strlen($text) <= $characters ? $text : mb_substr($text, 0, $characters) . $marker;
    }

    /** As cutText(), over valid UTF-8 first, as feed text may hold invalid bytes. */
    public static function cutScrubbedText(string $text, int $characters): string
    {
        return self::cutText(mb_scrub($text, 'UTF-8'), $characters);
    }

    /** As cutText(), with the whitespace the clip ends on dropped before the ellipsis. */
    public static function cutTrimmedText(string $text, int $characters): string
    {
        return mb_strlen($text) <= $characters ? $text : rtrim(mb_substr($text, 0, $characters)) . '…';
    }

    private function __construct()
    {
    }
}
