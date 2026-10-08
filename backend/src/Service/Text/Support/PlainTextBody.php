<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/**
 * A feed body with no markup and a line break is plain text: rendered as HTML its breaks would collapse, so blank
 * lines become paragraphs and single breaks <br>. Entities stay as the feed wrote them; only < and > are escaped.
 */
final class PlainTextBody
{
    private const string MARKUP_PATTERN = '/<[a-z\/!]/i';

    public static function asHtml(?string $body): ?string
    {
        if ($body === null || preg_match('/[\r\n]/', $body) !== 1 || preg_match(self::MARKUP_PATTERN, $body) === 1) {
            return $body;
        }

        return implode('', array_map(self::paragraph(...), self::paragraphs($body)));
    }

    /** @return list<string> */
    private static function paragraphs(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r", '<', '>'], ["\n", "\n", '&lt;', '&gt;'], $text);

        return preg_split('/\n\s*\n/', trim($normalised)) ?: [];
    }

    private static function paragraph(string $paragraph): string
    {
        return '<p>' . implode('<br>', array_map(trim(...), explode("\n", trim($paragraph)))) . '</p>';
    }

    private function __construct()
    {
    }
}
