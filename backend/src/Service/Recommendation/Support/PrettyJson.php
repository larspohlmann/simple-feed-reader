<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

/** Indented UTF-8 with slashes and non-ASCII as they are: a payload shown to a human, as it was sent. */
final class PrettyJson
{
    public static function of(mixed $value): string
    {
        return json_encode(
            $value,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    private function __construct()
    {
    }
}
