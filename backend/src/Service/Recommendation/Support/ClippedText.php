<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

final class ClippedText
{
    /**
     * Clips by character, not byte: this text goes into a JSON request body, and a German text cut mid-umlaut makes
     * json_encode fail.
     */
    public static function of(string $text, int $characters, string $marker = '…'): string
    {
        return mb_strlen($text) <= $characters ? $text : mb_substr($text, 0, $characters) . $marker;
    }

    /** As of(), over valid UTF-8 first, as feed text may hold invalid bytes. */
    public static function ofScrubbed(string $text, int $characters): string
    {
        return self::of(mb_scrub($text, 'UTF-8'), $characters);
    }

    private function __construct()
    {
    }
}
