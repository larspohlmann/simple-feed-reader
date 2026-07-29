<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reduces a possibly-HTML string to display-ready plain text: strips markup and
 * decodes HTML entities, then collapses runs of whitespace. Returns null when
 * nothing printable remains, so a caller can fall back (e.g. to '(untitled)').
 *
 * Feed <title> elements routinely carry entity-escaped HTML — "&lt;em&gt;" for
 * emphasis, "&amp;#8220;" for a curly quote — which the XML reader decodes one
 * level to a literal "<em>" tag or "&#8220;" reference. Rendered as text, that
 * markup leaks into the UI; this collapses it to the words a reader wants.
 *
 * The result is PLAIN TEXT: it may contain <, > and & as literal characters and
 * has NOT been through EntrySanitizer. Render it as text only, never with |raw.
 */
final class PlainText
{
    public static function from(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $decoded));

        return $collapsed === '' ? null : $collapsed;
    }
}
