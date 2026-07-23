<?php

declare(strict_types=1);

namespace App\Service\Scraper;

/** Cleans text extracted from scraped HTML (soft hyphens, run-on whitespace). */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        $text = str_replace("\u{00AD}", '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
