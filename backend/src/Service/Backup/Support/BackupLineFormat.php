<?php

declare(strict_types=1);

namespace App\Service\Backup\Support;

/** How every backup line is written: one JSON object per line, instants in UTC as ATOM strings. */
final class BackupLineFormat
{
    /**
     * @param array<string, mixed> $line
     */
    public static function encode(array $line): string
    {
        return json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public static function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    public static function dateOrNull(?\DateTimeImmutable $date): ?string
    {
        return null === $date ? null : self::date($date);
    }

    private function __construct()
    {
    }
}
