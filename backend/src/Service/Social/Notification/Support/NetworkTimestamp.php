<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

final class NetworkTimestamp
{
    public static function toUtc(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        try {
            return (new \DateTimeImmutable($timestamp, $utc))->setTimezone($utc);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }

    private function __construct()
    {
    }
}
