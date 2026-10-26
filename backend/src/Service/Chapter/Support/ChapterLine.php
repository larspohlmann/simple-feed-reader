<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

use App\Entity\EntryChapter;

/** A line with one stamp anywhere or a leading range; its title is the rest, minus the stamp's brackets. */
final class ChapterLine
{
    private const string STAMP = '(?<![\d:=])(?:\d{1,2}:)?\d{1,2}:\d{2}(?![\d:])';
    private const string STAMPS = '/' . self::STAMP . '/';
    private const string BRACKETED_STAMP = '/\(' . self::STAMP . '\)|\[' . self::STAMP . '\]|' . self::STAMP . '/';
    private const string LEADING_RANGE = '/^\s*(' . self::STAMP . ')\s*[-–—~]\s*' . self::STAMP . '(.*)$/su';
    private const string SEPARATORS_AT_THE_ENDS = '/^[\s\-–—:|]+|[\s\-–—:|]+$/u';

    public static function chapterOf(string $text): ?EntryChapter
    {
        return match (preg_match_all(self::STAMPS, $text)) {
            1 => self::chapter(self::firstStamp($text), (string) preg_replace(self::BRACKETED_STAMP, ' ', $text, 1)),
            2 => self::rangeChapterOf($text),
            default => null,
        };
    }

    public static function holdsNoStamp(string $text): bool
    {
        return preg_match(self::STAMPS, $text) !== 1;
    }

    private static function rangeChapterOf(string $text): ?EntryChapter
    {
        if (preg_match(self::LEADING_RANGE, $text, $range) !== 1) {
            return null;
        }

        return self::chapter($range[1], $range[2]);
    }

    private static function firstStamp(string $text): string
    {
        preg_match(self::STAMPS, $text, $stamp);

        return $stamp[0] ?? '';
    }

    private static function chapter(string $stamp, string $rest): ?EntryChapter
    {
        $start = ChapterClock::seconds($stamp);
        $title = (string) preg_replace(self::SEPARATORS_AT_THE_ENDS, '', (string) preg_replace('/\s+/u', ' ', $rest));

        return $start === null || $title === '' ? null : new EntryChapter($start, $title);
    }

    private function __construct()
    {
    }
}
