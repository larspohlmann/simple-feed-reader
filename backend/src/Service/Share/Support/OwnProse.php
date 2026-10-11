<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Text\Support\PlainText;

/** An entry's own words: its text without the links that read as their URL and the `Label: number` metadata lines. */
final class OwnProse
{
    private const string LINK = '#<a\b[^>]*>(?<text>.*?)</a>#isu';
    private const string URL_TEXT = '#^(?:https?://|www\.|[a-z0-9-]+(?:\.[a-z0-9-]+)+/)#iu';
    private const string METADATA_LINE = '/^[#\p{L}][\p{L}\p{N} #]{0,40}:\s*[\p{N}.,]*$/u';

    public static function of(string $html): string
    {
        $withoutUrlLinks = preg_replace_callback(self::LINK, self::droppedWhenUrlText(...), $html) ?? $html;
        $lines = array_filter(
            PlainText::linesFromHtmlBlocks($withoutUrlLinks),
            static fn (string $line): bool => preg_match(self::METADATA_LINE, $line) !== 1,
        );

        return implode("\n", $lines);
    }

    /** @param array<string> $link */
    private static function droppedWhenUrlText(array $link): string
    {
        return preg_match(self::URL_TEXT, trim(strip_tags($link['text']))) === 1 ? ' ' : $link[0];
    }

    private function __construct()
    {
    }
}
