<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Support;

/** UTC calendar days as 'Y-m-d' strings, which compare correctly as strings. */
final class UtcDay
{
    /** The instant must hold a UTC wall clock (naive UTC from Doctrine, or NaiveUtcClock); it is not converted. */
    public static function of(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d');
    }

    public static function start(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00');
    }

    public static function shift(string $day, int $days): string
    {
        return self::start($day)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function __construct()
    {
    }
}
