<?php

declare(strict_types=1);

namespace App\Service\Chapter\Support;

/** A chapter start as written in a description, a Podlove `start` or a clock: `S`, `M:SS`, `H:MM:SS`, any fraction floored. */
final class ChapterClock
{
    private const string PATTERN = '/^\d{1,9}(?::\d{1,9}){0,2}(?:\.\d+)?$/';

    public static function seconds(string $clock): ?int
    {
        $trimmed = trim($clock);
        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            return null;
        }

        $parts = explode(':', $trimmed);
        $total = 0;
        foreach ($parts as $index => $part) {
            if ($index > 0 && (int) $part > 59) {
                return null;
            }
            $total = $total * 60 + (int) $part;
        }

        return $total;
    }

    private function __construct()
    {
    }
}
