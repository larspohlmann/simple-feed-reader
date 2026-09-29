<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/**
 * Strips markup, decodes entities and collapses whitespace; null when nothing printable remains. The result is not
 * sanitized and may hold literal <, > and &: render it as text, never as HTML.
 */
final class PlainText
{
    /** Only fromHtmlBlocks() uses it: from() also reads feed titles, where a tag boundary is no word break. */
    private const string BLOCK_BOUNDARY_PATTERN = '/<\/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|thead|tbody'
        . '|blockquote|section|article|header|footer|aside|nav|figure|figcaption|dd|dt|dl)\b[^>]*>/i';

    public static function from(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $collapsed = Whitespace::collapse($decoded);

        return $collapsed === '' ? null : $collapsed;
    }

    /** from() for an entry body: block tags become spaces first, or "<p>one</p><p>two</p>" would read "onetwo". */
    public static function fromHtmlBlocks(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $withBoundaries = preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $html) ?? $html;

        return self::from($withBoundaries);
    }

    private function __construct()
    {
    }
}
