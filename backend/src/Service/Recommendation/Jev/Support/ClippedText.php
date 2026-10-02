<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

final class ClippedText
{
    /** At most $characters characters of valid UTF-8, an ellipsis marking a cut: feed text may hold invalid bytes. */
    public static function of(string $text, int $characters): string
    {
        $valid = mb_scrub($text, 'UTF-8');

        return mb_strlen($valid) <= $characters ? $valid : mb_substr($valid, 0, $characters) . '…';
    }

    private function __construct()
    {
    }
}
