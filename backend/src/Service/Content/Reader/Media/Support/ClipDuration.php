<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Support;

/** A clip's length as the body carries it: an ISO `PT…S` for machines, a clock for readers. */
final class ClipDuration
{
    private const string WHOLE_SECONDS = '/^PT(\d+)S$/D';
    private const int MINUTE = 60;
    private const int HOUR = 3600;

    private function __construct()
    {
    }

    public static function toIso(int $seconds): string
    {
        return 'PT' . $seconds . 'S';
    }

    public static function fromIso(?string $iso): ?int
    {
        if (preg_match(self::WHOLE_SECONDS, $iso ?? '', $match) !== 1 || (int) $match[1] === 0) {
            return null;
        }

        return (int) $match[1];
    }

    public static function clock(int $seconds): string
    {
        $minutes = intdiv($seconds, self::MINUTE);
        if ($seconds < self::HOUR) {
            return \sprintf('%d:%02d', $minutes, $seconds % self::MINUTE);
        }

        return \sprintf('%d:%02d:%02d', intdiv($seconds, self::HOUR), $minutes % self::MINUTE, $seconds % self::MINUTE);
    }
}
