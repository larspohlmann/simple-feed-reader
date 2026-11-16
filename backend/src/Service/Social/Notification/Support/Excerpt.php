<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

use App\Service\Foundation\Text\Support\ClippedText;

final class Excerpt
{
    private const int LENGTH = 280;

    public static function of(?string $text): ?string
    {
        $trimmed = trim($text ?? '');

        return $trimmed === '' ? null : ClippedText::ofScrubbed($trimmed, self::LENGTH);
    }

    private function __construct()
    {
    }
}
