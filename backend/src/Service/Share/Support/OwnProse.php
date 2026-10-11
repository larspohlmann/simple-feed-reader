<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Text\Support\PlainText;

/** An entry's own words: its text without the links that read as their URL and the `Label: number` metadata lines. */
final class OwnProse
{
    private const string URL_TEXT_LINK = '#<a\b[^>]*>\s*(?:https?://|www\.|[a-z0-9-]+(?:\.[a-z0-9-]+)+/)[^<]*</a>#iu';
    private const string METADATA_LINE = '/^[#\p{L}][\p{L}\p{N} #]{0,40}:\s*[\p{N}.,]*$/u';

    public static function of(string $html): string
    {
        $withoutUrlLinks = preg_replace(self::URL_TEXT_LINK, ' ', $html) ?? $html;
        $lines = array_filter(
            PlainText::linesFromHtmlBlocks($withoutUrlLinks),
            static fn (string $line): bool => preg_match(self::METADATA_LINE, $line) !== 1,
        );

        return implode("\n", $lines);
    }

    private function __construct()
    {
    }
}
