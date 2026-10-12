<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

final class RetryAfterHeader
{
    private const string DELAY = '/^\d+$/D';

    /** Only the delay form; a value we cannot read, a date included, names no wait. */
    public static function seconds(?string $header): ?int
    {
        $value = trim($header ?? '');

        return 1 === preg_match(self::DELAY, $value) ? (int) $value : null;
    }

    /** The delay form, or an HTTP date (RFC 9110) counted from $now; a date already past names no wait. */
    public static function secondsFrom(?string $header, \DateTimeImmutable $now): ?int
    {
        $delay = self::seconds($header);
        if (null !== $delay || null === $header) {
            return $delay;
        }

        $reopensAt = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, trim($header));
        if (false === $reopensAt) {
            return null;
        }
        $seconds = $reopensAt->getTimestamp() - $now->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    private function __construct()
    {
    }
}
