<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class DateParser
{
    /**
     * Lenient date parsing: RFC 2822, ISO 8601, and anything else PHP
     * understands. Unparsable input becomes null — a missing date must never
     * kill the whole feed.
     *
     * The result is normalised to UTC. Feed dates commonly carry a timezone
     * offset (e.g. `+0200`); persisting the offset's wall-clock naively would
     * store a time ahead of the UTC clock the rest of the app uses, landing such
     * entries in the future and rendering them as "now" (#48).
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
}
