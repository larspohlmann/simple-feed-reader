<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Text\Support\PlainText;
use Dom\Element;

/** An entry's own words: its text without the links that read as a URL and the `Label: number` metadata lines. */
final class OwnProse
{
    private const string METADATA_LINE = '/^[#\p{L}][\p{L}\p{N} #]{0,40}:\s*[\p{N}.,]*$/u';

    public static function of(?Element $body): string
    {
        if ($body === null) {
            return '';
        }

        $lines = array_filter(
            PlainText::linesFromHtmlBlocks(self::withoutUrlLinks($body)),
            static fn (string $line): bool => preg_match(self::METADATA_LINE, $line) !== 1,
        );

        return implode("\n", $lines);
    }

    private static function withoutUrlLinks(Element $body): string
    {
        $words = $body->cloneNode(true);
        \assert($words instanceof Element);
        foreach ($words->querySelectorAll('a') as $anchor) {
            if (UrlLinkText::readsAsUrl($anchor)) {
                $anchor->replaceWith(' ');
            }
        }

        return $words->innerHTML;
    }

    private function __construct()
    {
    }
}
