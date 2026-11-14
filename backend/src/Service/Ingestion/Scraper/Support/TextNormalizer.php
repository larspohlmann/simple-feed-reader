<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Scraper\Support;

use App\Service\Foundation\Text\Support\Whitespace;

final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        return Whitespace::collapse(str_replace("\u{00AD}", '', $text));
    }

    private function __construct()
    {
    }
}
