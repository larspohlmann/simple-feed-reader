<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

final class DateParser
{
    /**
     * Lenient: RFC 2822, ISO 8601 or anything else PHP reads; unparsable input is null, never a failed feed.
     * Normalised to UTC: stored naive, a kept offset would put the entry hours ahead, shown as "now" (#48).
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value)))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function __construct()
    {
    }
}
