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
        if ($body === null || preg_match(self::MARKUP_PATTERN, $body) === 1) {
            return $body;
        }

        if (!str_contains($body, "\n") && !str_contains($body, "\r")) {
            return $body;
        }

        return ParagraphedText::asHtml($body, self::escapeAngleBrackets(...));
    }

    private static function escapeAngleBrackets(string $line): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $line);
    }

    private function __construct()
    {
    }
}
