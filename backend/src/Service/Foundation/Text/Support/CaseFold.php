<?php

declare(strict_types=1);

namespace App\Service\Foundation\Text\Support;

final class CaseFold
{
    /** Casefolded, then in the Normalizer $form; only casefolded when the text cannot be normalised. */
    public static function normalized(string $text, int $form): string
    {
        $folded = mb_convert_case($text, \MB_CASE_FOLD, 'UTF-8');
        $normalized = \Normalizer::normalize($folded, $form);

        return \is_string($normalized) ? $normalized : $folded;
    }

    private function __construct()
    {
    }
}
