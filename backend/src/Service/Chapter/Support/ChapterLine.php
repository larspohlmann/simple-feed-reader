<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;

/** A description line holding exactly one stamp, anywhere; its title is the rest, without the stamp's brackets. */
final class ChapterLine
{
    private const string STAMP = '(?<![\d:])(?:\d{1,2}:)?\d{1,2}:\d{2}(?![\d:])';
    private const string STAMPS = '/' . self::STAMP . '/';
    private const string BRACKETED_STAMP = '/\(' . self::STAMP . '\)|\[' . self::STAMP . '\]|' . self::STAMP . '/';
    private const string SEPARATORS_AT_THE_ENDS = '/^[\s\-–—:|]+|[\s\-–—:|]+$/u';

    public static function chapterOf(string $text): ?EntryChapter
    {
        if (preg_match_all(self::STAMPS, $text, $stamps) !== 1) {
            return null;
        }
        $start = ChapterClock::seconds($stamps[0][0]);
        $title = self::titleOf($text);

        return $start === null || $title === '' ? null : new EntryChapter($start, $title);
    }

    public static function holdsNoStamp(string $text): bool
    {
        return preg_match(self::STAMPS, $text) !== 1;
    }

    private static function titleOf(string $text): string
    {
        $unstamped = (string) preg_replace(self::BRACKETED_STAMP, ' ', $text, 1);
        $collapsed = (string) preg_replace('/\s+/u', ' ', $unstamped);

        return (string) preg_replace(self::SEPARATORS_AT_THE_ENDS, '', $collapsed);
    }

    private function __construct()
    {
    }
}
