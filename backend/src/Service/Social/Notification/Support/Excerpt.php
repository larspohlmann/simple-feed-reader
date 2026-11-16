<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

final class Excerpt
{
    private const int LENGTH = 280;

    public static function of(?string $text): ?string
    {
        $trimmed = trim($text ?? '');

        return $trimmed === '' ? null : mb_substr($trimmed, 0, self::LENGTH);
    }

    private function __construct()
    {
    }
}
