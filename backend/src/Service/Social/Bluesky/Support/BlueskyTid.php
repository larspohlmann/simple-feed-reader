<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

/** A timestamp identifier, the record key a Bluesky post must carry: 53 bits of microseconds, then a 10-bit clock id. */
final class BlueskyTid
{
    private const string SORTABLE_BASE32 = '234567abcdefghijklmnopqrstuvwxyz';
    private const int LENGTH = 13;
    private const int BITS_PER_CHARACTER = 5;
    private const int CLOCK_ID_BITS = 10;

    private function __construct()
    {
    }

    public static function at(\DateTimeImmutable $moment, int $clockId): string
    {
        $microseconds = (int) $moment->format('Uu');
        $value = $microseconds << self::CLOCK_ID_BITS | $clockId & ((1 << self::CLOCK_ID_BITS) - 1);

        $tid = '';
        for ($index = 0; $index < self::LENGTH; ++$index) {
            $tid = self::SORTABLE_BASE32[$value & 0b11111] . $tid;
            $value >>= self::BITS_PER_CHARACTER;
        }

        return $tid;
    }
}
