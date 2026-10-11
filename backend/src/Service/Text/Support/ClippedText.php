<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

final class ClippedText
{
    /** Clips by character, not byte: a text cut mid-umlaut is invalid UTF-8, which json_encode rejects. */
    public static function of(string $text, int $characters, string $marker = '…'): string
    {
        return mb_strlen($text) <= $characters ? $text : mb_substr($text, 0, $characters) . $marker;
    }

    /** As of(), over valid UTF-8 first, as feed text may hold invalid bytes. */
    public static function ofScrubbed(string $text, int $characters): string
    {
        return self::of(mb_scrub($text, 'UTF-8'), $characters);
    }

    /** As of(), with the whitespace the clip ends on dropped before the ellipsis. */
    public static function trimmedOf(string $text, int $characters): string
    {
        return mb_strlen($text) <= $characters ? $text : rtrim(mb_substr($text, 0, $characters)) . '…';
    }

    private function __construct()
    {
    }
}
