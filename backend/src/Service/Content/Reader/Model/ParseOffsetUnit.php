<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

/** What IntlDateFormatter::parse() counts its offset in: PHP 8.4.27 switched from code points to bytes. */
enum ParseOffsetUnit
{
    case Bytes;
    case CodePoints;

    private const string NON_ASCII_DATE = '1. März 2026';

    public static function reportedBy(\IntlDateFormatter $germanLongDate): self
    {
        $position = 0;
        $germanLongDate->parse(self::NON_ASCII_DATE, $position);

        return $position === \strlen(self::NON_ASCII_DATE) ? self::Bytes : self::CodePoints;
    }

    public function lengthOf(string $text): int
    {
        return match ($this) {
            self::Bytes => \strlen($text),
            self::CodePoints => mb_strlen($text),
        };
    }
}
