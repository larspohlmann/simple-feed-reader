<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

final class FittingPrefix
{
    /**
     * The longest prefix of $text, in whole characters, that $fits accepts; $text itself when it fits. $fits must
     * accept '' and never accept a prefix longer than one it refused.
     *
     * @param \Closure(string): bool $fits
     */
    public static function of(string $text, \Closure $fits): string
    {
        if ($fits($text)) {
            return $text;
        }

        $fitting = 0;
        $refused = mb_strlen($text);
        while ($refused - $fitting > 1) {
            $middle = intdiv($fitting + $refused, 2);
            if ($fits(mb_substr($text, 0, $middle))) {
                $fitting = $middle;
            } else {
                $refused = $middle;
            }
        }

        return mb_substr($text, 0, $fitting);
    }

    private function __construct()
    {
    }
}
