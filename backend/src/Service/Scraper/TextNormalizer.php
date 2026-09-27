<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Text\Whitespace;

final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        return Whitespace::collapse(str_replace("\u{00AD}", '', $text));
    }
}
