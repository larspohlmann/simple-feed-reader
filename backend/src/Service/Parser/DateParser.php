<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class DateParser
{
    /**
     * Lenient date parsing: RFC 2822, ISO 8601, and anything else PHP
     * understands. Unparsable input becomes null — a missing date must never
     * kill the whole feed.
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
