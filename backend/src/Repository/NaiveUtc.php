<?php

declare(strict_types=1);

namespace App\Repository;

/** Reads a datetime a native query returns: Doctrine stores wall-clock UTC without an offset. */
final class NaiveUtc
{
    public static function parse(string $stored): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
    }
}
